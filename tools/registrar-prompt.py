#!/usr/bin/env python3
"""registrar-prompt.py — Hook UserPromptSubmit de Claude Code.

Registra textualmente cada prompt enviado a AGENTE 1 UTN / AGENTE 2 UTN en
doc/ia/registro/ (condición del TP: documentar todos los prompts utilizados).

- Solo se invoca si la sesión fue lanzada con la variable AGENTE (ver CLAUDE.md).
- Escribe dos formatos: JSONL (fuente fiel, una línea por prompt) y Markdown
  (lectura humana).
- Nunca escribe en stdout: en UserPromptSubmit lo que se imprime se inyecta como
  contexto del agente. Ante cualquier error sale con 0 para no bloquear el prompt.
"""
import json
import os
import re
import sys
from datetime import datetime
from pathlib import Path

REGISTRO = Path(__file__).resolve().parent.parent / "doc" / "ia" / "registro"
ID_RE = re.compile(r"\[(A[12]-\d{2,3}[a-z]?)\]")
# Defensa mínima: si por error se pega un secreto, no queda en un repo público.
SECRET_RE = re.compile(
    r"(?i)(password|passwd|pass|secret|token|api[_-]?key)(\s*[:=]\s*)(['\"]?)[^\s'\"]{6,}\3"
)


def main() -> None:
    agente = os.environ.get("AGENTE", "").strip()
    if not agente:
        return
    data = json.load(sys.stdin)
    prompt = SECRET_RE.sub(r"\1\2\3[REDACTADO]\3", data.get("prompt", ""))
    ahora = datetime.now().astimezone()
    match = ID_RE.search(prompt)
    registro = {
        "ts": ahora.isoformat(timespec="seconds"),
        "agente": agente,
        "id": match.group(1) if match else None,
        "session_id": data.get("session_id"),
        "prompt": prompt,
    }

    REGISTRO.mkdir(parents=True, exist_ok=True)
    dia = ahora.strftime("%Y-%m-%d")
    with open(REGISTRO / f"{dia}.jsonl", "a", encoding="utf-8") as f:
        f.write(json.dumps(registro, ensure_ascii=False) + "\n")
    with open(REGISTRO / f"{dia}.md", "a", encoding="utf-8") as f:
        f.write(
            f"\n## {registro['id'] or '(sin ID)'} · {agente} · {ahora.strftime('%H:%M:%S')}\n\n"
            f"````text\n{prompt}\n````\n"
        )


if __name__ == "__main__":
    try:
        main()
    except Exception:  # noqa: BLE001 — el hook nunca debe bloquear al agente
        pass
    sys.exit(0)
