"""Selected chat effort reaches Codex without changing delegation permissions or defaults."""
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import claude_bridge as bridge
import codex_entry


class ReasoningSettingsTests(unittest.TestCase):
    def chat(self, **extra):
        body = {'executor': 'codex', 'subject': 'GPT', 'space_type': 'creation', 'mode': 'chat',
                'caller_uid': bridge.COGNOS_USER, **extra}
        job = {'space_id': '7', 'run_id': 'a' * 32, 'prompt': '只回一句', 'system': '当前对话'}
        return bridge.codex_job(body, job)

    def test_saved_effort_reaches_executor_with_read_only_chat_tools(self):
        job = self.chat(reasoning_effort='high')
        with patch.object(codex_entry, 'run', return_value={'content': '已接通'}) as run, \
             patch.object(bridge, 'codex_limits', return_value=None, create=True), patch.object(bridge, 'record_usage', create=True):
            self.assertEqual(bridge.run_codex(job), '已接通')
            self.assertEqual(run.call_args.kwargs['reasoning_effort'], 'high')
            self.assertNotIn('write_content', run.call_args.kwargs['tools'])
            self.assertNotIn('adopt', run.call_args.kwargs['tools'])

    def test_invalid_effort_is_rejected_before_dispatch(self):
        for value in ('unsupported', '', None, True, ['high']):
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.chat(reasoning_effort=value)

    def test_real_command_gets_override_and_default_leaves_it_unspecified(self):
        with patch.object(codex_entry.shutil, 'which', return_value='codex'):
            default = codex_entry.command(Path('.'), 'GPT')
            explicit = codex_entry.command(Path('.'), 'GPT', reasoning_effort='high')
        self.assertFalse(any('model_reasoning_effort' in a for a in default))
        self.assertIn('model_reasoning_effort="high"', explicit)
        self.assertIn('features.multi_agent=false', explicit)

    def test_cli_run_uses_effort_with_existing_run_binding(self):
        def simulate(command, **kwargs):
            self.assertIn('model_reasoning_effort="xhigh"', command)
            self.assertEqual(kwargs['env']['COGNOS_BOUND_RUN_ID'], 'a' * 32)
            Path(command[command.index('--output-last-message') + 1]).write_text('已接通', encoding='utf-8')
            return SimpleNamespace(returncode=0)
        with tempfile.TemporaryDirectory() as tmp, patch.object(codex_entry.shutil, 'which', return_value='codex'), \
             patch.object(codex_entry.subprocess, 'run', side_effect=simulate):
            r = codex_entry.run('7', '只回一句', Path(tmp), run_id='a' * 32,
                tools=('read_space',), reasoning_effort='xhigh', environment={'COGNOS_APP_PASSWORD': 'fixture-only'})
            self.assertEqual(r['content'], '已接通')


if __name__ == '__main__':
    unittest.main()
