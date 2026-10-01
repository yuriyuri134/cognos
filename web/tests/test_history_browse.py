import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from activity_acquisition import browse_project_history, read_project_occurrence
from activity_program import ProgramError


class HistoryBrowseTest(unittest.TestCase):
    def test_cursor_survives_new_records_and_exposes_nonmatches(self):
        records = [{"id": str(i), "project_id": "p", "author": "原作者",
                    "content": "生活已经有保障" if i == 0 else "赚钱"} for i in range(25)]
        records += [{"id": "receipt", "project_id": "p", "author": "Cognos", "content": "回执", "activity_acquisition": True},
                    {"id": "other", "project_id": "q", "author": "外部", "content": "不属于此项目"}]
        comparison = {"provider": "project_history", "contains": "赚钱"}
        first = browse_project_history(records, "p", comparison=comparison)
        self.assertEqual(20, first["returned_count"])
        self.assertEqual(5, first["older_count"])
        records.append({"id": "new", "project_id": "p", "author": "原作者", "content": "新消息"})
        second = browse_project_history(records, "p", before=first["next_before"], comparison=comparison)
        self.assertEqual(["4", "3", "2", "1", "0"], [item["occurrence_id"] for item in second["records"]])
        self.assertFalse(second["records"][-1]["matches_current_filters"])
        self.assertIsNone(second["next_before"])
        self.assertEqual("生活已经有保障", read_project_occurrence(records, "p", "0")["content"])
        with self.assertRaises(ProgramError):
            browse_project_history(records, "p", before="other")
        with self.assertRaises(ProgramError):
            browse_project_history(records, "p", limit=True)

    def test_excerpt_never_claims_to_be_full_text(self):
        records = [{"id": "r", "project_id": "p", "author": "作者", "content": "开头" * 500}]
        item = browse_project_history(records, "p")["records"][0]
        self.assertEqual(500, len(item["content"]))
        self.assertTrue(item["content_truncated"])
        self.assertEqual(1000, item["total_characters"])
        self.assertIsNone(item["matches_current_filters"])
