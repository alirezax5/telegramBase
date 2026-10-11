#!/usr/bin/env python3
"""Build llms-ctx.txt: single-file flattened context for LLM ingestion.
Concatenates README + docs/ + key meta files with clear section markers.
Run from the release root:  python3 build_llm_ctx.py
"""
from pathlib import Path

ROOT = Path(__file__).parent
OUT = ROOT / "llms-ctx.txt"

ORDER = [
    ("PROJECT", "README.md"),
    ("DOC: OVERVIEW", "docs/en/overview.md"),
    ("DOC: ARCHITECTURE", "docs/en/architecture.md"),
    ("DOC: GETTING STARTED", "docs/en/getting-started.md"),
    ("DOC: BOT MODES", "docs/en/bot-modes.md"),
    ("DOC: MULTI-BOT", "docs/en/multi-bot.md"),
    ("DOC: CACHING", "docs/en/caching.md"),
    ("DOC: SESSIONS", "docs/en/sessions.md"),
    ("DOC: I18N", "docs/en/i18n.md"),
    ("DOC: BUTTONS", "docs/en/buttons.md"),
    ("DOC: PLUGINS", "docs/en/plugins.md"),
    ("DOC: QUEUE PIPELINE", "docs/en/queue-pipeline.md"),
    ("DOC: WEBHOOK", "docs/en/webhook.md"),
    ("DOC: RELIABILITY", "docs/en/reliability.md"),
    ("DOC: CONFIGURATION (.env reference)", "docs/en/configuration.md"),
    ("DOC: FEATURES", "docs/en/features.md"),
    ("DOC: TESTING", "docs/en/testing.md"),
    ("DOC: TROUBLESHOOTING", "docs/en/troubleshooting.md"),
    ("META: ENV TEMPLATE", ".env.example"),
    ("META: CHANGELOG (current version)", "CHANGELOG.md"),
]

def main() -> None:
    parts = [
        "TelegramBase — flattened LLM context",
        "=====================================",
        "",
        "This file contains the complete documentation of TelegramBase",
        f"(version v2.4.0) concatenated for LLM ingestion. Generated from",
        "README.md + docs/*.md + .env.example + CHANGELOG.md.",
        "Source of truth: the repository (https://github.com/alirezax5/telegramBase).",
        "",
    ]
    for label, rel in ORDER:
        p = ROOT / rel
        if not p.exists():
            continue
        text = p.read_text(encoding="utf-8").strip()
        bar = "=" * 70
        parts += ["", bar, f"SECTION: {label}  ({rel})", bar, "", text, ""]
    OUT.write_text("\n".join(parts) + "\n", encoding="utf-8")
    print(f"wrote {OUT} ({OUT.stat().st_size} bytes)")

if __name__ == "__main__":
    main()
