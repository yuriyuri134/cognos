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


if __name__=='__main__': unittest.main()
