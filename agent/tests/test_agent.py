import importlib.util
import unittest
from pathlib import Path

MODULE = Path(__file__).parents[1] / "bwproxy-agent.py"
spec = importlib.util.spec_from_file_location("bwproxy_agent", MODULE)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class ValidationTests(unittest.TestCase):
    def test_valid_off(self):
        agent.validate_setting({"server_uuid": "abc-123", "mode": "off"})

    def test_rejects_unknown_mode(self):
        with self.assertRaises(ValueError):
            agent.validate_setting({"server_uuid": "abc", "mode": "global"})

    def test_rejects_invalid_port(self):
        with self.assertRaises(ValueError):
            agent.validate_setting({"server_uuid": "abc", "mode": "socks5",
                                    "socks5_host": "proxy.example", "socks5_port": 70000})

    def test_rejects_config_injection_host(self):
        with self.assertRaises(ValueError):
            agent.validate_setting({"server_uuid": "abc", "mode": "socks5",
                                    "socks5_host": "host; daemon = on", "socks5_port": 1080})

    def test_escapes_redsocks_values(self):
        self.assertEqual(agent.quote_redsocks('a"b\\c'), '"a\\"b\\\\c"')

    def test_deterministic_port(self):
        self.assertEqual(agent.server_port("server-1"), agent.server_port("server-1"))
        self.assertGreaterEqual(agent.server_port("server-1"), 20000)
        self.assertLess(agent.server_port("server-1"), 40000)

    def test_rejects_invalid_awg_interface(self):
        with self.assertRaises(ValueError):
            agent.validate_setting({"server_uuid": "abc", "mode": "amneziawg",
                                    "awg_interface": "awg0;reboot"})


if __name__ == "__main__":
    unittest.main()
