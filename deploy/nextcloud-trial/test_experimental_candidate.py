"""No-Docker checks for candidate preflight and isolation gating."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from experimental_candidate import validate_candidate, install_candidate


def app(directory, version, app_id="cognos"):
    info = directory / "appinfo"
    info.mkdir(parents=True)
    (info / "info.xml").write_text(
        f"<info><id>{app_id}</id><version>{version}</version></info>", encoding="utf-8")


class ExperimentTests(unittest.TestCase):
    def test_rejects_live_and_older_sources(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            live, candidate = base / "live", base / "isolated"
            app(live, "0.37.2")
            app(candidate, "0.37.1")
            with self.assertRaisesRegex(ValueError, "live"):
                validate_candidate(live, live, "0.37.2")
            with self.assertRaisesRegex(ValueError, "older"):
                validate_candidate(candidate, live, "0.37.2")
            app_file = candidate / "appinfo" / "info.xml"
            app_file.write_text('<info><id>wrong</id><version>0.40.0</version></info>')
            with self.assertRaisesRegex(ValueError, "not the Cognos"):
                validate_candidate(candidate, live, "0.37.2")
            app_file.write_text('<info><id>cognos</id><version>0.40.0</version></info>')
            self.assertEqual(validate_candidate(candidate, live, "0.37.2"), (candidate, "0.40.0"))

    def test_no_mutation_without_restore_labels_and_quarantine(self):
        with tempfile.TemporaryDirectory() as temp:
            candidate = Path(temp)
            docker = Mock()
            occ = Mock(return_value="true\n")
            inspect = Mock(return_value={"Config":{"Labels":{"cognos.restore":"abc123ffff"}},
                                         "NetworkSettings":{"Networks":{"cognos-restore-abc123ffff-net":{}}}})
            state = dict(prefix="cognos-restore-abc123ffff", app="cognos-restore-abc123ffff-app",
                         network="cognos-restore-abc123ffff-net", html_volume="cognos-restore-abc123ffff-html",
                         no_cron=True, no_host_bridge=True)
            with self.assertRaisesRegex(RuntimeError, "dedicated"):
                install_candidate(candidate, "0.40.0", {**state, "html_volume":"production-html"}, "image", docker, inspect, occ)
            docker.assert_not_called()
            inspect.return_value["Config"]["Labels"] = {}
            with self.assertRaisesRegex(RuntimeError, "quarantined"):
                install_candidate(candidate, "0.40.0", state, "image", docker, inspect, occ)
            docker.assert_not_called()

    def test_isolated_staging_sequence_and_fail_closed(self):
        candidate = Path("/tmp/separate-cognos-app")
        state = dict(prefix="cognos-restore-123456789a", app="cognos-restore-123456789a-app",
                     network="cognos-restore-123456789a-net", html_volume="cognos-restore-123456789a-html",
                     no_cron=True, no_host_bridge=True)
        inspect = Mock(return_value={"Config":{"Labels":{"cognos.restore":"123456789a"}},
                                     "NetworkSettings":{"Networks":{state["network"]:{}}}})
        docker = Mock()
        occ = Mock(side_effect=["true", "", '{"installed":true,"needsDbUpgrade":false}', "true"])
        result = install_candidate(candidate, "0.40.0", state, "immutable-image", docker, inspect, occ)
        self.assertEqual(result["version"], "0.40.0")
        calls = docker.call_args_list
        self.assertEqual(calls[0].args, ("stop", state["app"]))
        self.assertIn("--network", calls[1].args)
        self.assertIn("none", calls[1].args)
        self.assertIn("readonly", " ".join(calls[1].args))
        self.assertEqual(calls[2].args, ("start", state["app"]))
        self.assertEqual(occ.call_args_list[1].args, (state["app"], "upgrade"))


if __name__ == "__main__":
    unittest.main()
