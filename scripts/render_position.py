#!/usr/bin/env python3
"""
Render a position chart (candles + levels + EMA + entry/exit + volume) from a
JSON spec produced by the PHP side (App\\Trading\\Charting\\ChartRenderer).

    python3 scripts/render_position.py <spec.json>

The spec carries everything needed to show *why* the agent entered/exited:
the candle series (flagged as compression/impulse), the key level, EMA8/EMA21,
the stop and targets, the entry marker and (optionally) the exit marker, plus
per-candle volume. Output PNG path is taken from the spec's "out" field.

Styling mirrors entry_points.py so position charts look consistent with the
strategy reference figure.
"""

import json
import os
import sys

import matplotlib
matplotlib.use("Agg")  # headless: no display in the render environment
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
import matplotlib.lines as mlines
from matplotlib.gridspec import GridSpec

BULL = "#26a69a"   # bullish candle
BEAR = "#ef5350"   # bearish candle
COMP = "#ffa726"   # compression candle
IMP_UP = "#00c853"  # bullish impulse
IMP_DN = "#d50000"  # bearish impulse
LEVEL = "#1565c0"
EMA_FAST_CLR = "#e91e63"
EMA_SLOW_CLR = "#7b1fa2"
VOL_CLR = "#90a4ae"

plt.rcParams["font.family"] = "DejaVu Sans"
plt.rcParams["axes.facecolor"] = "#fafafa"


def candle_color(c):
    if c.get("impulse") == "bull":
        return IMP_UP
    if c.get("impulse") == "bear":
        return IMP_DN
    if c.get("compression"):
        return COMP
    return BULL if c["c"] >= c["o"] else BEAR


def draw_candles(ax, candles):
    for i, c in enumerate(candles):
        o, h, l, cl = c["o"], c["h"], c["l"], c["c"]
        col = candle_color(c)
        body_bottom = min(o, cl)
        body_height = max(abs(cl - o), (h - l) * 0.02 or 0.01)
        ax.add_patch(mpatches.Rectangle(
            (i - 0.35, body_bottom), 0.7, body_height,
            facecolor=col, zorder=3, linewidth=0.5, edgecolor="#333",
        ))
        ax.plot([i, i], [l, body_bottom], color="#333", lw=1.0, zorder=2)
        ax.plot([i, i], [max(o, cl), h], color="#333", lw=1.0, zorder=2)


def add_marker(ax, idx, price, direction, label):
    up = direction == "up"
    color = "#00c853" if up else "#d50000"
    marker = "^" if up else "v"
    dy = (ax.get_ylim()[1] - ax.get_ylim()[0]) * 0.06
    ax.plot(idx, price, marker=marker, color=color, markersize=15,
            markeredgecolor="#222", zorder=7)
    ax.annotate(
        label, xy=(idx, price),
        xytext=(idx, price + dy if up else price - dy),
        fontsize=8, color=color, fontweight="bold", ha="center", zorder=8,
        arrowprops=dict(arrowstyle="->", color=color, lw=1.5),
    )


def main(spec_path, out_override=None):
    with open(spec_path) as fh:
        spec = json.load(fh)
    if out_override:
        spec["out"] = out_override

    candles = spec["candles"]
    n = len(candles)
    xs = list(range(n))

    fig = plt.figure(figsize=(14, 9))
    fig.patch.set_facecolor("#f5f5f5")
    gs = GridSpec(2, 1, height_ratios=[3, 1], hspace=0.08)
    ax = fig.add_subplot(gs[0])
    axv = fig.add_subplot(gs[1], sharex=ax)

    fig.suptitle(spec.get("title", "Position"), fontsize=14, fontweight="bold")

    draw_candles(ax, candles)

    # EMA lines.
    if spec.get("ema_fast"):
        ax.plot(xs, spec["ema_fast"], color=EMA_FAST_CLR, lw=1.6,
                label=f"EMA {spec.get('ema_fast_period', 8)}", zorder=4)
    if spec.get("ema_slow"):
        ax.plot(xs, spec["ema_slow"], color=EMA_SLOW_CLR, lw=1.6, ls="--",
                label=f"EMA {spec.get('ema_slow_period', 21)}", zorder=4)

    # Key level, stop and targets.
    ax.axhline(spec["level"], color=LEVEL, lw=2, ls="--",
               label=f"Уровень = {spec['level']:.4g}", zorder=1)
    if spec.get("stop") is not None:
        ax.axhline(spec["stop"], color="#d50000", lw=1.2, ls=":", alpha=0.85,
                   label=f"Стоп = {spec['stop']:.4g}")
    for key, clr in (("target1", "#2e7d32"), ("target2", "#1b5e20")):
        if spec.get(key) is not None:
            ax.axhline(spec[key], color=clr, lw=1, ls="-.", alpha=0.7,
                       label=f"{key.upper()} = {spec[key]:.4g}")

    # Entry / exit markers.
    if spec.get("entry"):
        e = spec["entry"]
        add_marker(ax, e["index"], e["price"], e["direction"], e["label"])
    if spec.get("exit"):
        x = spec["exit"]
        add_marker(ax, x["index"], x["price"], x["direction"], x["label"])

    ax.set_ylabel("Цена")
    ax.grid(True, alpha=0.3)
    ax.legend(loc="best", fontsize=7, ncol=2)
    ax.set_xlim(-1, n)

    # Volume subplot.
    vols = [c.get("v", 0) for c in candles]
    vcolors = [candle_color(c) for c in candles]
    axv.bar(xs, vols, color=vcolors, width=0.7, alpha=0.6)
    axv.set_ylabel("Объём")
    axv.grid(True, alpha=0.3)
    axv.set_xlabel("Свеча")

    legend_elements = [
        mpatches.Patch(facecolor=BULL, label="Бычья"),
        mpatches.Patch(facecolor=BEAR, label="Медвежья"),
        mpatches.Patch(facecolor=COMP, label="Сжатие"),
        mpatches.Patch(facecolor=IMP_UP, label="Импульс вверх"),
        mpatches.Patch(facecolor=IMP_DN, label="Импульс вниз"),
        mlines.Line2D([], [], color=EMA_FAST_CLR, lw=2, label="EMA быстрая"),
        mlines.Line2D([], [], color=EMA_SLOW_CLR, lw=2, ls="--", label="EMA медленная"),
    ]
    fig.legend(handles=legend_elements, loc="lower center", ncol=7,
               fontsize=8, frameon=True, bbox_to_anchor=(0.5, 0.0))

    out = spec["out"]
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    fig.savefig(out, dpi=140, bbox_inches="tight")
    plt.close(fig)
    print(out)


if __name__ == "__main__":
    if len(sys.argv) not in (2, 3):
        print("usage: render_position.py <spec.json> [out.png]", file=sys.stderr)
        sys.exit(2)
    main(sys.argv[1], sys.argv[2] if len(sys.argv) == 3 else None)
