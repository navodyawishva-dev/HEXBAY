from app import create_app


def test_health_endpoint():
    client = create_app().test_client()
    response = client.get("/internal/health")

    assert response.status_code == 200
    payload = response.get_json()
    assert payload["success"] is True
    assert payload["data"]["sprint"] == 5
    assert "health_check" in payload["data"]["features_enabled"]
    assert "laptop_recommendation" in payload["data"]["features_enabled"]
