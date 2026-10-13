"""测试辅助函数。"""

from httpx import AsyncClient


async def login_test_user(client: AsyncClient, username: str = "testuser", password: str = "test123",
                          display_name: str = "小林") -> None:
    """注册并登录测试用户。如果用户已存在，只登录。"""
    # 尝试注册
    await client.post("/api/auth/register", json={
        "username": username,
        "password": password,
        "display_name": display_name
    })
    # 登录（无论注册是否成功）
    resp = await client.post("/api/auth/login", json={"username": username, "password": password})
    assert resp.status_code == 200, f"登录失败: {resp.text}"
