import json
import subprocess
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

DOCKER = "/Applications/Docker.app/Contents/Resources/bin/docker"
FIXTURE = "/var/www/html/jwp_dev-plugin/wp-content/plugins/jPlugin-Formbuilder/tests/browser-fixture.php"
ARTIFACTS = Path("/tmp/jfb-browser-smoke")


def docker_php(*args: str) -> str:
    process = subprocess.run(
        [DOCKER, "exec", "php-v82", "php", FIXTURE, *args],
        check=True,
        capture_output=True,
        text=True,
    )
    return process.stdout.strip().splitlines()[-1]


def normalize_browser_url(raw_url: str) -> str:
    parts = urlsplit(raw_url)
    if parts.hostname and parts.hostname.endswith("-80.local"):
        hostname = parts.hostname.removesuffix("-80.local") + ".local"
        netloc = hostname
        if parts.port:
            netloc = f"{hostname}:{parts.port}"
        return urlunsplit(("https", netloc, parts.path, parts.query, parts.fragment))
    return raw_url


fixture = json.loads(docker_php("setup"))
fixture["admin_url"] = normalize_browser_url(fixture["admin_url"])
fixture["builder_url"] = normalize_browser_url(fixture["builder_url"])
fixture["public_url"] = normalize_browser_url(fixture["public_url"])
ARTIFACTS.mkdir(parents=True, exist_ok=True)
errors: list[str] = []
admin_origin = "{0.scheme}://{0.netloc}".format(urlsplit(fixture["admin_url"]))

try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 1000}, ignore_https_errors=True)
        context.add_cookies([
            {"name": cookie["name"], "value": cookie["value"], "url": admin_origin}
            for cookie in fixture["cookies"]
        ])
        page = context.new_page()
        page.on("console", lambda message: errors.append(f"console:{message.type}:{message.text}") if message.type == "error" else None)
        page.on("pageerror", lambda error: errors.append(f"pageerror:{error}"))

        page.goto(fixture["admin_url"], wait_until="networkidle")
        if page.locator("[data-jfb-admin]").count() == 0:
            page.screenshot(path=str(ARTIFACTS / "admin-missing.png"), full_page=True)
            body = page.locator("body").inner_text()[:800]
            raise RuntimeError(f"Admin root missing url={page.url} title={page.title()} body={body}")
        assert page.get_by_role("heading", name="Forms", exact=True).is_visible()
        page.screenshot(path=str(ARTIFACTS / "admin-forms-1440.png"), full_page=True)

        page.goto(fixture["builder_url"], wait_until="networkidle")
        page.locator("[data-jfb-builder]").wait_for()
        page.locator("[data-new-field]").select_option("date")
        page.locator("[data-add-field]").click()
        page.locator("[data-save-form]").click()
        page.locator(".jfb-toast.is-visible").wait_for()
        try:
            page.locator("[data-save-state]").filter(has_text="Saved").wait_for(timeout=10000)
        except PlaywrightTimeoutError as exc:
            page.screenshot(path=str(ARTIFACTS / "admin-builder-save-failed.png"), full_page=True)
            save_state = page.locator("[data-save-state]").inner_text()
            toast_text = page.locator(".jfb-toast").inner_text()
            raise RuntimeError(f"Builder save state={save_state!r} toast={toast_text!r}") from exc
        page.screenshot(path=str(ARTIFACTS / "admin-builder-1440.png"), full_page=True)

        page.goto(fixture["public_url"], wait_until="networkidle")
        page.locator("[data-jfb-form]").wait_for()
        page.locator('input[name="name"]').fill("Browser Tester")
        page.locator('input[name="email"]').fill("browser@example.com")
        page.locator('textarea[name="message"]').fill("Responsive browser smoke test")
        page.wait_for_timeout(2200)
        page.locator(".jfb-submit").click()
        page.locator(".jfb-success").wait_for(timeout=10000)
        page.screenshot(path=str(ARTIFACTS / "public-form-1440.png"), full_page=True)

        for width in (768, 320):
            page.set_viewport_size({"width": width, "height": 900})
            page.goto(fixture["public_url"], wait_until="networkidle")
            page.locator("[data-jfb-form]").wait_for()
            assert page.locator("body").evaluate("el => el.scrollWidth <= el.clientWidth")
            page.screenshot(path=str(ARTIFACTS / f"public-form-{width}.png"), full_page=True)

        browser.close()

    if errors:
        raise RuntimeError("; ".join(errors))
    print(f"PASS screenshots={ARTIFACTS}")
finally:
    docker_php("cleanup", str(fixture["user_id"]), str(fixture["page_id"]), fixture["form_uuid"])
