"""Codex delegation boundaries, using local stubs for the model and HTTP delivery."""
import io
import json
import tempfile
import unittest
import urllib.error
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import claude_bridge as bridge
import codex_entry
import server

RUN = 'a' * 32
DELEGATION = 'b' * 32


def request(path='/codex', **changes):
    body = {'executor': 'codex', 'subject': 'Codex', 'space_type': 'creation', 'space_id': '7',
            'run_id': RUN, 'delegation_id': DELEGATION, 'caller_uid': bridge.COGNOS_USER,
            'system': '当前入口', 'history': [], 'message': '委托原话', 'caller': '用户显示名', **changes}
    payload = json.dumps(body).encode()
    responses = []
    handler = SimpleNamespace(path=path, rfile=io.BytesIO(payload), send_json=lambda status, data: responses.append((status, data)),
                              headers={'Content-Length': str(len(payload)), 'X-Cognos-Bridge-Token': bridge.TOKEN})
    bridge.Handler.do_POST(handler)
    return responses[0]


class CodexDelegationTests(unittest.TestCase):
    def test_endpoint_rejects_wrong_scope_executor_identity_and_configuration(self):
        with patch.object(bridge.threading, 'Thread') as worker, patch.object(bridge, 'codex_configured', return_value=True):
            for fields in ({'executor':'claude'}, {'subject':'Claude Code'}, {'space_type':'other'},
                           {'space_id':'0'}, {'caller_uid':'somebody-else'}, {'run_id':'Z'*32},
                           {'delegation_id':'bad'}, {'repo':True}, {'open_id':12}, {'hit_id':1},
                           {'base_branch':'main'}, {'may_edit':'true'}):
                with self.subTest(fields=fields):
                    self.assertEqual(request(**fields)[0], 400)
            self.assertEqual(request('/chat')[0], 400)
            worker.assert_not_called()
        with patch.object(bridge, 'codex_configured', return_value=False), patch.object(bridge.threading, 'Thread') as worker:
            self.assertEqual(request()[0], 422)
            worker.assert_not_called()

    def test_accepted_codex_uses_the_same_space_busy_and_inflight_records(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge, 'OUTBOX_DIR', Path(tmp)), \
             patch.object(bridge, 'BUSY', set()), patch.object(bridge, 'codex_configured', return_value=True), \
             patch.object(bridge.threading, 'Thread') as worker:
            self.assertEqual(request(may_manage_work=True)[0], 202)
            accepted = worker.call_args.kwargs['args'][0]
            self.assertEqual((accepted['executor'], accepted['run_id'], accepted['delegation_id']), ('codex', RUN, DELEGATION))
            self.assertFalse(accepted['may_edit'])
            self.assertFalse(accepted['may_adopt'])
            self.assertTrue(accepted['may_manage_work'])
            inflight = json.loads((Path(tmp) / 'inflight' / (RUN + '.json')).read_text(encoding='utf-8'))
            self.assertEqual(inflight['caller_uid'], bridge.COGNOS_USER)
            self.assertEqual(inflight['executor'], 'codex')
            self.assertEqual(request()[0], 409)
            worker.assert_called_once()

    def test_executor_posts_one_codex_result_through_existing_outbox(self):
        job = {'space_type':'creation', 'space_id':'7', 'subject':'Codex', 'run_id':RUN,
               'executor':'codex', 'caller_uid':bridge.COGNOS_USER}
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge, 'OUTBOX_DIR', Path(tmp)), \
             patch.object(bridge, 'BUSY', {'creation/7'}), patch.object(bridge, 'run_codex', return_value='最终答复') as codex, \
             patch.object(bridge, 'run_claude') as claude, patch.object(bridge, 'workdir_for') as workdir:
            bridge.mark_inflight(job)
            with patch.object(bridge, 'cognos_post', side_effect=urllib.error.URLError('simulated missing receipt')) as post:
                bridge.work(job)
                result_id = post.call_args.args[1]['result_id']
            codex.assert_called_once_with(job)
            claude.assert_not_called()
            workdir.assert_not_called()
            self.assertFalse(bridge.BUSY)
            self.assertFalse(list((Path(tmp) / 'inflight').glob('*.json')))
            self.assertTrue((Path(tmp) / 'pending' / (result_id + '.json')).is_file())
            with patch.object(bridge, 'cognos_post') as post:
                self.assertEqual(bridge.resend_pending(), 0)
                self.assertEqual(bridge.resend_pending(), 0)
                post.assert_called_once()
                path, body = post.call_args.args
                self.assertEqual(path, 'spaces/creation/7/messages')
                self.assertEqual((body['run_id'], body['result_id'], body['content'], body['subject']), (RUN, result_id, '最终答复', 'Codex'))
                self.assertNotIn('caller_uid', body)
                self.assertEqual(post.call_args.kwargs, {'executor':'codex'})

    def test_cli_uses_actual_last_message_with_run_bound_tools_and_credentials(self):
        tools = codex_entry.delegation_tools(may_edit=True, may_adopt=False, may_manage_work=True)
        self.assertIn('write_content', tools)
        self.assertIn('update_work_item', tools)
        self.assertIn('split_work_item', tools)
        self.assertNotIn('adopt', tools)
        self.assertNotIn('start_work', tools)
        self.assertNotIn('hand_back', tools)
        def simulate(command, **kwargs):
            self.assertIn('--ephemeral', command)
            self.assertIn('--ignore-user-config', command)
            self.assertIn('read-only', command)
            self.assertNotIn('test-app-credential', ' '.join(command))
            self.assertEqual(kwargs['env']['COGNOS_APP_PASSWORD'], 'test-app-credential')
            self.assertEqual(kwargs['env']['COGNOS_BOUND_RUN_ID'], RUN)
            self.assertEqual(kwargs['env']['COGNOS_BOUND_SPACE_ID'], '7')
            self.assertEqual(kwargs['env']['COGNOS_TOOLS'].split(','), list(tools))
            self.assertNotIn('test-app-credential', kwargs['input'])
            kwargs['stdout'].write('{"type":"item.completed","item":{"type":"agent_message","text":"中途说明"}}\n')
            Path(command[command.index('--output-last-message') + 1]).write_text('最终答复\n', encoding='utf-8')
            return SimpleNamespace(returncode=0)
        with tempfile.TemporaryDirectory() as tmp, patch.object(codex_entry.shutil, 'which', return_value='codex'), \
             patch.object(codex_entry.subprocess, 'run', side_effect=simulate):
            result = codex_entry.run('7', '任务入口', Path(tmp), run_id=RUN, tools=tools,
                                     environment={'COGNOS_APP_PASSWORD':'test-app-credential'})
            self.assertEqual(result['content'], '最终答复')

    def test_bound_mcp_rejects_other_runs_and_forwards_the_exact_run_to_writes(self):
        with patch.object(server, 'BOUND_ID', '7'), patch.object(server, 'BOUND_TYPE', 'creation'), \
             patch.object(server, 'BOUND_RUN_ID', RUN), patch.object(server, 'SUBJECT', 'Codex'), \
             patch.object(server, 'ONLY', set()), patch.object(server, 'call_api', return_value={'message':'saved'}) as api:
            for tool in ('note_basis', 'write_content', 'adopt', 'check_changes', 'update_work_item', 'split_work_item', 'start_work', 'hand_back'):
                with self.subTest(tool=tool), self.assertRaises(RuntimeError):
                    server.run_tool(tool, {'space_id':'7','run_id':'c'*32})
            with self.assertRaises(RuntimeError):
                server.run_tool('note_basis', {'space_id': '8', 'revision': 1})
            api.assert_not_called()
            for tool, args, endpoint in [('note_basis', {'revision':1}, '/basis'),
                                         ('write_content', {'content':'改稿'}, '/content'),
                                         ('adopt', {'object_type':'message','object_id':'2'}, '/adoptions')]:
                self.assertEqual(server.run_tool(tool, args), 'saved')
                self.assertEqual(api.call_args.args[1], '/creation/7' + endpoint)
                self.assertEqual(api.call_args.kwargs['body']['run_id'], RUN)
                self.assertEqual(api.call_args.kwargs['body']['subject'], 'Codex')
            for tool in ('start_work', 'hand_back'):
                with self.assertRaises(RuntimeError):
                    server.run_tool(tool, {'space_id':'7','run_id':RUN})
            self.assertEqual(api.call_count, 3)


if __name__ == '__main__':
    unittest.main()
