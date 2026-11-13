"""ChatGPT Chat is a separate executor; an unavailable browser never falls back to Codex."""
import io
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import claude_bridge as bridge


def body(**changes):
    return {'executor':'chatgpt','subject':'ChatGPT','mode':'chat','space_type':'creation','space_id':'7',
            'caller_uid':bridge.COGNOS_USER,'run_id':'a'*32,'system':'当前入口','message':'原话','history':[],**changes}


class ChatGptEntryTests(unittest.TestCase):
    def test_provider_scope_and_efforts_are_separate_from_codex(self):
        job={'space_id':'7'}
        self.assertEqual(bridge.chatgpt_job(body(reasoning_effort='high'),job)['reasoning_effort'],'high')
        for fields in ({'executor':'codex'},{'subject':'GPT'},{'caller_uid':'someone-else'},
                       {'reasoning_effort':'extended'},{'repo':True},{'delegation_id':'b'*32}):
            with self.subTest(fields=fields), self.assertRaises(ValueError):
                bridge.chatgpt_job(body(**fields),job)

    def test_disconnected_browser_does_not_accept_or_run_a_model(self):
        responses=[];payload=json.dumps(body()).encode()
        handler=SimpleNamespace(path='/chatgpt',rfile=io.BytesIO(payload),
            headers={'Content-Length':str(len(payload)),'X-Cognos-Bridge-Token':bridge.TOKEN},
            send_json=lambda status,data:responses.append((status,data)))
        with patch.object(bridge,'chatgpt_browser',return_value={'ready':False,'message':'请先登录'}), \
             patch.object(bridge,'mark_inflight') as mark, patch.object(bridge.threading,'Thread') as worker:
            bridge.Handler.do_POST(handler)
            self.assertEqual(responses[0][0],428);mark.assert_not_called();worker.assert_not_called()

    def test_chat_reply_uses_only_the_browser_executor(self):
        job=bridge.chatgpt_job(body(),{'space_id':'7','space_type':'creation','run_id':'a'*32,
            'subject':'ChatGPT','prompt':'原话','system':'入口','reply_to':1})
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge,'OUTBOX_DIR',Path(tmp)), \
             patch.object(bridge,'BUSY',{'creation/7'}), patch.object(bridge,'chatgpt_browser',return_value={'content':'聊天端回答'}) as chat, \
             patch.object(bridge,'run_codex') as codex, patch.object(bridge,'run_claude') as claude, patch.object(bridge,'deliver'):
            bridge.work(job);chat.assert_called_once_with('reply',{'prompt':'原话','reasoning_effort':'default','run_id':'a'*32,'space_id':'7'})
            codex.assert_not_called();claude.assert_not_called()
            result=json.loads(next((Path(tmp)/'pending').glob('*.json')).read_text(encoding='utf-8'))
            self.assertEqual(result['subject'],'ChatGPT');self.assertEqual(result['content'],'聊天端回答')


class ChatGptThreadTests(unittest.TestCase):
    """One Cognos conversation keeps one ChatGPT chat; only what ChatGPT has not seen is sent."""

    def test_continue_only_when_the_conversation_still_matches(self):
        url = 'https://chatgpt.com/c/abc-123'
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge, 'CHATGPT_THREADS_DIR', Path(tmp)):
            first = bridge.chatgpt_thread('7', [], '第一句', '小林', '入口一')
            self.assertNotIn('thread_url', first)
            bridge.remember_chatgpt_thread('7', first, url, '第一次回答')
            history = [{'role': 'user', 'content': '第一句'}, {'role': 'assistant', 'content': '第一次回答', 'author': 'ChatGPT'},
                       {'role': 'assistant', 'content': 'Claude 插了一句', 'author': 'Claude Code'}]
            nxt = bridge.chatgpt_thread('7', history, '第二句', '小林', '入口一')
            self.assertEqual(nxt['thread_url'], url)
            self.assertIn('Claude Code：Claude 插了一句', nxt['followup'])
            self.assertIn('## 小林现在说\n第二句', nxt['followup'])
            self.assertNotIn('第一次回答', nxt['followup'])
            self.assertNotIn('入口一', nxt['followup'])
            changed = bridge.chatgpt_thread('7', history, '第二句', '小林', '入口二')
            self.assertIn('入口二', changed['followup'])
            edited = [{'role': 'user', 'content': '改过的第一句'}] + history[1:]
            self.assertNotIn('thread_url', bridge.chatgpt_thread('7', edited, '第二句', '小林', '入口一'))
            self.assertNotIn('thread_url', bridge.chatgpt_thread('8', history, '第二句', '小林', '入口一'))

    def test_reply_is_linked_and_remembered(self):
        job = bridge.chatgpt_job(body(), {'space_id': '7', 'space_type': 'creation', 'run_id': 'a' * 32,
            'subject': 'ChatGPT', 'prompt': '原话', 'system': '入口', 'reply_to': 1})
        answer = {'content': '聊天端回答', 'source_url': 'https://chatgpt.com/c/xyz-9'}
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge, 'OUTBOX_DIR', Path(tmp)), \
             patch.object(bridge, 'CHATGPT_THREADS_DIR', Path(tmp) / 'threads'), \
             patch.object(bridge, 'BUSY', {'creation/7'}), patch.object(bridge, 'chatgpt_browser', return_value=answer), \
             patch.object(bridge, 'deliver'):
            bridge.work(job)
            result = json.loads(next((Path(tmp) / 'pending').glob('*.json')).read_text(encoding='utf-8'))
            self.assertTrue(result['content'].startswith('聊天端回答'))
            self.assertIn('(https://chatgpt.com/c/xyz-9)', result['content'])
            saved = json.loads((Path(tmp) / 'threads' / 'creation-7.json').read_text(encoding='utf-8'))
            self.assertEqual(saved['url'], 'https://chatgpt.com/c/xyz-9')
            history = [{'role': 'user', 'content': '原话'}, {'role': 'assistant', 'content': result['content'], 'author': 'ChatGPT'}]
            self.assertEqual(bridge.chatgpt_thread('7', history, '下一句', '小林', '入口').get('thread_url'), saved['url'])

    def test_connects_when_not_connected_before_refusing(self):
        calls = []
        def fake(action='status', job=None):
            calls.append(action)
            if action == 'open':
                return {'ready': False, 'state': 'connecting'}
            return {'ready': len(calls) > 3, 'state': 'connecting' if len(calls) > 1 else 'disconnected'}
        with patch.object(bridge, 'chatgpt_browser', side_effect=fake), patch.object(bridge.time, 'sleep'):
            self.assertTrue(bridge.chatgpt_ready()['ready'])
        self.assertIn('open', calls)

    def test_explicit_bind_without_and_with_history_then_unbind(self):
        url = 'https://chatgpt.com/c/bound-42'
        with tempfile.TemporaryDirectory() as tmp, patch.object(bridge, 'CHATGPT_THREADS_DIR', Path(tmp)):
            bound = bridge.bind_chatgpt_thread('9', url + '/?utm=1#top', [], '入口')
            self.assertEqual(bound['url'], url)
            self.assertEqual(bridge.get_chatgpt_thread('9')['mode'], 'bound')
            prior = [{'role': 'user', 'content': '早先说过'}, {'role': 'assistant', 'content': 'Claude 回过', 'author': 'Claude Code'}]
            t1 = bridge.chatgpt_thread('9', prior, '现在接着问', '小林', '入口')
            self.assertEqual(t1['thread_url'], url)
            self.assertIn('Claude Code：Claude 回过', t1['followup'])
            self.assertIn('## 这一处现在的样子', t1['followup'])
            self.assertIn('## 小林现在说\n现在接着问', t1['followup'])
            imported = prior + [{'role': 'user', 'content': '原问'}, {'role': 'assistant', 'content': '原答', 'author': 'ChatGPT'}]
            bridge.bind_chatgpt_thread('9', url, imported, '')
            t2 = bridge.chatgpt_thread('9', imported, '接着说', '小林', '入口')
            self.assertEqual(t2['thread_url'], url)
            self.assertNotIn('原答', t2['followup'])
            self.assertIn('## 小林现在说\n接着说', t2['followup'])
            bridge.bind_chatgpt_thread('9', '')
            self.assertIsNone(bridge.get_chatgpt_thread('9'))
            with self.assertRaises(ValueError):
                bridge.bind_chatgpt_thread('9', 'https://evil.example/c/1')


if __name__=='__main__': unittest.main()
