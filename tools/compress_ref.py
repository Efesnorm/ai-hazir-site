"""
Uzun ARKA PLAN belgelerini LLMLingua-2 ile sıkıştırır.

Kullanım:
    pip install llmlingua
    python tools/compress_ref.py docs/ref/rakip-arastirmasi.md --rate 0.5

Çıktı: docs/ref/rakip-arastirmasi.min.md

UYARI: CLAUDE.md, görev dosyaları (docs/gorevler/) ve kod SIKIŞTIRILMAZ.
Bu dosyalardaki isimler, kurallar ve sayılar birebir korunmalıdır.
"""

import argparse
from pathlib import Path

from llmlingua import PromptCompressor

# Sıkıştırmada asla atılmaması gereken işaretler.
FORCE_TOKENS = ["\n", ".", "?", "!", ":", "-", "|", "#"]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("path", type=Path)
    parser.add_argument("--rate", type=float, default=0.5,
                        help="Korunacak oran (0.5 = yarısı kalır)")
    args = parser.parse_args()

    if "gorevler" in args.path.parts or args.path.name == "CLAUDE.md":
        raise SystemExit("Görev dosyaları ve CLAUDE.md sıkıştırılmaz.")

    text = args.path.read_text(encoding="utf-8")

    compressor = PromptCompressor(
        model_name="microsoft/llmlingua-2-xlm-roberta-large-meetingbank",
        use_llmlingua2=True,
        device_map="cpu",
    )
    result = compressor.compress_prompt(text, rate=args.rate, force_tokens=FORCE_TOKENS)

    out = args.path.with_suffix(".min.md")
    out.write_text(result["compressed_prompt"], encoding="utf-8")
    print(f"{args.path} -> {out} | {result['origin_tokens']} -> {result['compressed_tokens']} token")


if __name__ == "__main__":
    main()
