"""Literal bounded-choice wire protocol shared by Python experiments.

Mirrors the deliberately narrow PHP BoundedChoiceValidator grammar.
A model decision is only one JSON object with a literal "selected" property,
whose value is exactly "none" or an in-range canonical "cand_N" index.
Invalid and unavailable responses both map to None. Callers must count their
causes separately; None is not proof of semantic abstention.
"""
from __future__ import annotations

import re

_WIRE = re.compile(
    r'[ \t\r\n]*\{[ \t\r\n]*"selected"[ \t\r\n]*:[ \t\r\n]*'
    r'"(none|cand_(0|[1-9][0-9]*))"[ \t\r\n]*\}[ \t\r\n]*'
)


def is_explicit_none_response(raw: str) -> bool:
    """True only for the valid literal 'none' protocol value, never failures.

    Keep this grammar and the UTF-8 byte limit in sync with the selector
    validator; the validator's None result also covers invalid responses.
    """
    try:
        if len(raw.encode("utf-8")) > 1024:
            return False
    except UnicodeError:
        return False
    match = _WIRE.fullmatch(raw)
    return match is not None and match.group(1) == "none"


def validate_bounded_choice_response(raw: str, candidates: list[str]) -> str | None:
    """Return the verified source ID, or None on none/invalid/unavailable.

    The regex deliberately rejects duplicate keys, escaped JSON tokens,
    Unicode whitespace and noncanonical numbers *before* JSON decoding could
    silently collapse or normalize them.
    """
    try:
        if len(raw.encode("utf-8")) > 1024:
            return None
    except UnicodeError:
        return None
    match = _WIRE.fullmatch(raw)
    if match is None or match.group(1) == "none":
        return None
    digits = match.group(2)
    if digits is None or len(digits) > 9:
        return None
    index = int(digits)
    return candidates[index] if 0 <= index < len(candidates) else None
