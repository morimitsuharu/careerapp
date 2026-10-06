"""HTTP integration checks using a disposable SQLite database."""
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


def run():
    with tempfile.TemporaryDirectory(prefix="careerapp-test-") as temp:
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        env = {**os.environ, "DB_DRIVER": "sqlite", "DB_PATH": str(Path(temp) / "test.sqlite"), "APP_SKIP_INITIAL_COMPANIES": "1"}
        with open(Path(temp) / "server.log", "w") as log:
            server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public"], cwd=ROOT, env=env, stdout=log, stderr=log)
            try:
                base = f"http://127.0.0.1:{port}"
                opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                for _ in range(50):
                    try:
                        initial = json.load(opener.open(base + "/api.php", timeout=2))
                        break
                    except urllib.error.URLError:
                        time.sleep(0.1)
                else:
                    raise RuntimeError("Test server did not start")
                csrf = initial["csrf"]

                def api(payload, expected=200, token=csrf):
                    req = urllib.request.Request(base + "/api.php", json.dumps(payload).encode(), {"Content-Type": "application/json", "X-CSRF-Token": token})
                    try:
                        res = opener.open(req)
                    except urllib.error.HTTPError as error:
                        res = error
                    assert res.code == expected, (res.code, res.read().decode())
                    return json.load(res)

                def save(entity, values, id=0, expected=200):
                    return api({"action": "save", "entity": entity, "id": id, "values": values}, expected)

                assert all(not rows for rows in initial["data"].values())
                api({"action": "sample"}, 403, "invalid")
                company = {"name": "テスト企業 <script>", "industry": "IT", "url": "https://example.com", "notes": "研究メモ"}
                save("companies", {**company, "url": "javascript:alert(1)"}, expected=422)
                save("companies", {**company, "secondary_url": "javascript:alert(1)"}, expected=422)
                save("companies", {**company, "url": "https://official.example@evil.example/"}, expected=422)
                company.update(category="大手IT", tags="企画,マーケ", secondary_url="https://example.org/")
                cid = save("companies", company)["data"]["companies"][0]["id"]
                edited = save("companies", {**company, "notes": "追記"}, cid)["data"]["companies"][0]
                assert edited["tags"] == "企画,マーケ" and edited["secondary_url"] == "https://example.org/"
                opp = {"company_id": cid, "title": "企画インターン", "deadline": "2026-12-01T23:59", "status": "writing", "priority": "high", "url": "", "notes": "募集メモ"}
                save("opportunities", {**opp, "deadline": "2026-02-30T12:00"}, expected=422)
                save("opportunities", {**opp, "company_id": 999999}, expected=422)
                save("opportunities", {**opp, "opens_at": "2026-13"}, expected=422)
                save("opportunities", {**opp, "additional_deadlines": "2026-02-30"}, expected=422)
                opp.update(opens_at="2026-11", eligibility="eligible", eligibility_notes="学年不問", checked_at="2026-10-06", recruitment_state="open", additional_deadlines="2026-12-15T10:00")
                oid = save("opportunities", opp)["data"]["opportunities"][0]["id"]
                saved = save("opportunities", {**opp, "deadline": "2026-12-31"}, oid)["data"]["opportunities"][0]
                assert saved["deadline"] == "2026-12-31" and saved["opens_at"] == "2026-11"
                assert save("opportunities", {**opp, "status": "applied"}, oid)["data"]["opportunities"][0]["status"] == "applied"
                api({"action": "delete", "entity": "companies", "id": cid}, 422)
                exp = {"title": "チーム開発", "period": "大学2年", "situation": "課題", "action": "行動", "result": "結果", "learning": "学び", "tags": "開発,チームワーク"}
                eid = save("experiences", exp)["data"]["experiences"][0]["id"]
                doc = {"title": "ガクチカ", "opportunity_id": oid, "experience_id": eid, "question": "経験は？", "body": "私はチーム開発で…", "word_limit": 400, "tags": "開発", "status": "draft"}
                save("documents", {**doc, "word_limit": 0}, expected=422)
                did = save("documents", doc)["data"]["documents"][0]["id"]
                save("documents", {**doc, "status": "submitted"}, did)
                save("documents", {**doc, "body": "上書き"}, did, expected=422)
                assert len(save("documents", {**doc, "title": "ガクチカ（コピー）"})["data"]["documents"]) == 2
                save("events", {"title": "説明会", "opportunity_id": oid, "starts_at": "2026-11-01T14:00", "kind": "briefing", "notes": "準備"})
                api({"action": "sample"}, 422)
                result = api({"action": "delete", "entity": "opportunities", "id": oid})
                assert all(row["opportunity_id"] is None for row in result["data"]["documents"])
                assert result["data"]["events"][0]["opportunity_id"] is None
                result = api({"action": "delete", "entity": "experiences", "id": eid})
                assert all(row["experience_id"] is None for row in result["data"]["documents"])
                api({"action": "delete", "entity": "companies", "id": cid})
                persisted = json.load(opener.open(base + "/api.php"))["data"]
                assert len(persisted["documents"]) == 2
                for entity in ["documents", "events"]:
                    for row in persisted[entity]:
                        api({"action": "delete", "entity": entity, "id": row["id"]})
                sample = api({"action": "sample"})["data"]
                assert len(sample["companies"]) == 3 and len(sample["opportunities"]) == 3
                api({"action": "sample"}, 422)
                page = opener.open(base)
                assert "Content-Security-Policy" in page.headers
                assert "しおり" in page.read().decode()
                assert opener.open(base + "/assets/app.js").status == 200
                before = json.load(opener.open(base + "/api.php"))["data"]
                quick = {**doc, "title": "自由記述ES", "opportunity_id": "new", "new_company_name": "新規応募企業", "new_opportunity_title": "企画採用", "experience_id": "new", "new_experience_title": "部活動", "new_experience_detail": "運営改善", "status": "reviewing", "progress_note": "添削依頼"}
                save("documents", {**quick, "word_limit": 0}, expected=422)
                rolled = json.load(opener.open(base + "/api.php"))["data"]
                assert all(len(rolled[k]) == len(before[k]) for k in before), "Failed save left related records"
                created = save("documents", quick)["data"]
                newdoc = created["documents"][0]
                qid = newdoc["id"]
                assert newdoc["progress_log"][0]["note"] == "添削依頼"
                assert len(created["companies"]) == len(before["companies"]) + 1
                assert any(e["id"] == newdoc["experience_id"] and e["situation"] == "運営改善" for e in created["experiences"])
                revised = save("documents", {**newdoc, "status": "revising", "progress_note": "指摘を反映"}, qid)["data"]["documents"][0]
                assert revised["progress_log"][0]["from_status"] == "reviewing"
                assert revised["progress_log"][0]["to_status"] == "revising"
                same = save("documents", revised, qid)["data"]["documents"][0]
                assert len(same["progress_log"]) == 2, "Ordinary save duplicated progress"
                save("documents", {**revised, "status": "submitted"}, qid)
                locked_note = save("documents", {"status": "submitted", "progress_note": "提出確認済み"}, qid)["data"]["documents"][0]
                assert locked_note["body"] == newdoc["body"] and locked_note["progress_log"][0]["note"] == "提出確認済み"
                save("documents", {"status": "draft"}, qid, expected=422)
                shared = save("documents", {**quick, "title": "別のES", "new_opportunity_title": "別募集"})["data"]
                assert len(shared["companies"]) == len(created["companies"]), "Same company duplicated"
                assert opener.open(base + "/assets/app.js").status == 200
                print("PASS: CRUD, validation, CSRF, submitted ES lock, relationships, persistence, sample data, page/assets")
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    run()
