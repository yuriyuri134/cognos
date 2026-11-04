"""Bound-space MCP checks: cross-space and cross-participant hand-backs must fail before disclosure."""
import importlib.util
from pathlib import Path
import unittest
import os
import tempfile
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('bound_server',Path(__file__).with_name('server.py'))
server=importlib.util.module_from_spec(spec);spec.loader.exec_module(server)

class BoundEntryTests(unittest.TestCase):
    def test_other_space_cannot_be_read_or_started(self):
        with patch.object(server,'ONLY',set()),patch.object(server,'BOUND_ID','7'),patch.object(server,'BOUND_TYPE','creation'),patch.object(server,'call_api') as api:
            for tool in ('enter_space','start_work','list_work_items','get_work_item','preview_work_split'):
                with self.subTest(tool=tool),self.assertRaises(RuntimeError):
                    server.run_tool(tool,{'space_id':'8','work_id':'a'*32,'parts':[]})
            api.assert_not_called()

    def test_other_run_is_not_used_for_drift_or_handoff(self):
        with patch.object(server,'ONLY',set()),patch.object(server,'BOUND_ID','7'),patch.object(server,'BOUND_TYPE','creation'):
            for run in ({'space_type':'creation','space_id':'8','subject':server.SUBJECT},
                        {'space_type':'creation','space_id':'7','subject':'另一位'}):
                with patch.object(server,'call_api',return_value={'run':run}) as api:
                    with self.assertRaises(RuntimeError):server.run_tool('check_changes',{'run_id':'a'*32})
                    self.assertEqual(api.call_count,1)
                    self.assertTrue(api.call_args.kwargs['url'].endswith('/runs/'+'a'*32))

    def test_bound_drift_and_handoff_share_receipt_and_repeat_works_offline(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ,{'COGNOS_MCP_STATE_DIR':tmp}), patch.object(server,'ONLY',set()), patch.object(server,'BOUND_ID','7'), patch.object(server,'BOUND_TYPE','creation'):
            posts=[]
            def api(method,path,params=None,body=None,url=None):
                if method=='POST': posts.append(body);return {}
                if (url or '').endswith('/drift'):
                    return {'decisions_missing':[{'id':'new','content':'new decision'}],'basis_changed':[],'drift_token':'shown'}
                return {'run':{'space_type':'creation','space_id':'7','subject':server.SUBJECT}}
            with patch.object(server,'call_api',side_effect=api):
                server.run_tool('check_changes',{'run_id':'a'*32})
                args={'space_id':'7','run_id':'a'*32,'content':'review','acknowledged':True}
                self.assertEqual(server.run_tool('hand_back',args),'已交回。')
            self.assertEqual(len(posts),1)
            self.assertTrue(posts[0]['acknowledged_drift'])
            with patch.object(server,'call_api',side_effect=RuntimeError('offline')) as api:
                self.assertEqual(server.run_tool('hand_back',args),'已交回。')
                api.assert_not_called()

    def test_unbound_drift_schema_needs_only_run_id(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ,{'COGNOS_MCP_STATE_DIR':tmp}), patch.object(server,'ONLY',set()), patch.object(server,'BOUND_ID',''):
            replies=[{'run':{'space_type':'creation','space_id':'7','subject':server.SUBJECT}},
                     {'decisions_missing':[],'basis_changed':[],'drift_token':'same'}]
            with patch.object(server,'call_api',side_effect=replies):
                text=server.run_tool('check_changes',{'run_id':'a'*32})
                self.assertIn('没有发现新决定',text)

    def test_only_preview_is_exposed_for_split(self):
        names={t['name'] for t in server.TOOLS}
        self.assertTrue({'list_work_items','get_work_item','preview_work_split'}<=names)
        self.assertNotIn('split_work_item',names)
        with patch.object(server,'ONLY',set()),patch.object(server,'BOUND_ID',''),patch.object(server,'call_api',return_value={'basis':'b','consequences':[]}) as api:
            server.run_tool('preview_work_split',{'space_id':'7','work_id':'c'*32,'parts':[{'description':'甲'},{'description':'乙'}]})
            self.assertTrue(api.call_args.args[1].endswith('/split/preview'))

if __name__=='__main__':unittest.main()
