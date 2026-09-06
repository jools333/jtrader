<?php

declare(strict_types=1);

namespace App\Trading\Services;

use App\Models\Position;
use App\Services\Telegram\TelegramService;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Enums\SignalType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class DailyPositionReportService
{
    public function __construct(
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Build report data, format message(s), and send to Telegram if configured.
     *
     * @return array{
     *     success: bool,
     *     sent: bool,
     *     date: string,
     *     timezone: string,
     *     closed_count: int,
     *     open_count: int,
     *     net_pnl: float,
     *     message_chunks: array<string>,
     *     error: ?string
     * }
     */
    public function sendReport(
        CarbonInterface|string|null $date = null,
        ?string $chatId = null,
        ?string $timezone = null,
    ): array {
        $tz = $timezone ?? (string) config('services.telegram.report_timezone', config('app.timezone', 'UTC'));
        $targetDate = $this->resolveDate($date, $tz);

        $reportData = $this->buildReportData($targetDate, $tz);
        $messageChunks = $this->formatHtmlReport($reportData);

        $sent = false;
        $error = null;

        if ($this->telegram->isConfigured() || ! empty($chatId)) {
            $sent = $this->telegram->sendMessages($messageChunks, $chatId);
            if (! $sent) {
                $error = 'Не удалось отправить сообщение через Telegram API. Проверьте логи.';
            }
        } else {
            $error = 'Telegram не настроен (TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID не заданы).';
        }

        return [
            'success' => $sent || empty($error),
            'sent' => $sent,
            'date' => $targetDate->format('Y-m-d'),
            'timezone' => $tz,
            'closed_count' => $reportData['stats']['total_closed'],
            'open_count' => $reportData['open_positions']->count(),
            'net_pnl' => $reportData['stats']['net_pnl'],
            'message_chunks' => $messageChunks,
            'error' => $error,
        ];
    }

    /**
     * Compile statistical summary and position collections for the day.
     *
     * @return array{
     *     date: Carbon,
     *     timezone: string,
     *     closed_positions: Collection<int, Position>,
     *     open_positions: Collection<int, Position>,
     *     stats: array<string, mixed>
     * }
     */
    public function buildReportData(Carbon $date, string $timezone): array
    {
        $start = $date->copy()->startOfDay()->utc();
        $end = $date->copy()->endOfDay()->utc();

        /** @var Collection<int, Position> $closed */
        $closed = Position::query()
            ->where('status', Position::STATUS_CLOSED)
            ->whereBetween('closed_at', [$start, $end])
            ->orderBy('closed_at', 'asc')
            ->get();

        /** @var Collection<int, Position> $open */
        $open = Position::query()
            ->open()
            ->orderBy('opened_at', 'asc')
            ->get();

        $stats = $this->calculateStats($closed);

        return [
            'date' => $date,
            'timezone' => $timezone,
            'closed_positions' => $closed,
            'open_positions' => $open,
            'stats' => $stats,
        ];
    }

    /**
     * Calculate aggregate performance figures for closed positions.
     *
     * @param  Collection<int, Position>  $positions
     * @return array<string, mixed>
     */
    public function calculateStats(Collection $positions): array
    {
        $total = $positions->count();
        if ($total === 0) {
            return [
                'total_closed' => 0,
                'wins' => 0,
                'losses' => 0,
                'breakeven' => 0,
                'win_rate' => 0.0,
                'gross_pnl' => 0.0,
                'commission' => 0.0,
                'funding_fee' => 0.0,
                'total_fees' => 0.0,
                'net_pnl' => 0.0,
                'long_count' => 0,
                'long_wins' => 0,
                'long_win_rate' => 0.0,
                'long_net' => 0.0,
                'short_count' => 0,
                'short_wins' => 0,
                'short_win_rate' => 0.0,
                'short_net' => 0.0,
                'best_trade' => null,
                'worst_trade' => null,
            ];
        }

        $wins = 0;
        $losses = 0;
        $breakeven = 0;
        $grossPnl = 0.0;
        $commission = 0.0;
        $funding = 0.0;
        $netPnl = 0.0;

        $longCount = 0;
        $longWins = 0;
        $longNet = 0.0;

        $shortCount = 0;
        $shortWins = 0;
        $shortNet = 0.0;

        /** @var Position|null $bestTrade */
        $bestTrade = null;
        /** @var Position|null $worstTrade */
        $worstTrade = null;

        foreach ($positions as $pos) {
            $pNet = $pos->netPnl() ?? 0.0;
            $pGross = $pos->realized_pnl ?? 0.0;
            $pComm = $pos->commission ?? 0.0;
            $pFund = $pos->funding_fee ?? 0.0;

            $grossPnl += $pGross;
            $commission += $pComm;
            $funding += $pFund;
            $netPnl += $pNet;

            if ($pNet > 0.0001) {
                $wins++;
            } elseif ($pNet < -0.0001) {
                $losses++;
            } else {
                $breakeven++;
            }

            if ($pos->direction === Direction::Long->value) {
                $longCount++;
                $longNet += $pNet;
                if ($pNet > 0.0001) {
                    $longWins++;
                }
            } elseif ($pos->direction === Direction::Short->value) {
                $shortCount++;
                $shortNet += $pNet;
                if ($pNet > 0.0001) {
                    $shortWins++;
                }
            }

            if ($bestTrade === null || $pNet > ($bestTrade->netPnl() ?? 0.0)) {
                $bestTrade = $pos;
            }
            if ($worstTrade === null || $pNet < ($worstTrade->netPnl() ?? 0.0)) {
                $worstTrade = $pos;
            }
        }

        $winRate = $total > 0 ? ($wins / $total) * 100 : 0.0;
        $longWinRate = $longCount > 0 ? ($longWins / $longCount) * 100 : 0.0;
        $shortWinRate = $shortCount > 0 ? ($shortWins / $shortCount) * 100 : 0.0;

        return [
            'total_closed' => $total,
            'wins' => $wins,
            'losses' => $losses,
            'breakeven' => $breakeven,
            'win_rate' => round($winRate, 1),
            'gross_pnl' => round($grossPnl, 4),
            'commission' => round($commission, 4),
            'funding_fee' => round($funding, 4),
            'total_fees' => round($commission + $funding, 4),
            'net_pnl' => round($netPnl, 4),
            'long_count' => $longCount,
            'long_wins' => $longWins,
            'long_win_rate' => round($longWinRate, 1),
            'long_net' => round($longNet, 4),
            'short_count' => $shortCount,
            'short_wins' => $shortWins,
            'short_win_rate' => round($shortWinRate, 1),
            'short_net' => round($shortNet, 4),
            'best_trade' => $bestTrade,
            'worst_trade' => $worstTrade,
        ];
    }

    /**
     * Render the report into clean Telegram HTML message chunk(s).
     *
     * @param array{
     *     date: Carbon,
     *     timezone: string,
     *     closed_positions: Collection<int, Position>,
     *     open_positions: Collection<int, Position>,
     *     stats: array<string, mixed>
     * } $data
     * @return array<string>
     */
    public function formatHtmlReport(array $data): array
    {
        $dateStr = $data['date']->format('d.m.Y');
        $tz = $data['timezone'];
        $stats = $data['stats'];
        /** @var Collection<int, Position> $closed */
        $closed = $data['closed_positions'];
        /** @var Collection<int, Position> $open */
        $open = $data['open_positions'];

        $lines = [];
        $lines[] = "📊 <b>Отчет по позициям за {$dateStr}</b>";
        $lines[] = "⏱ Таймзона: <code>{$tz}</code>";
        $lines[] = '';

        if ($stats['total_closed'] === 0) {
            $lines[] = 'ℹ️ <i>За выбранный день закрытых сделок не было.</i>';
        } else {
            $pnlEmoji = $stats['net_pnl'] > 0 ? '🟢' : ($stats['net_pnl'] < 0 ? '🔴' : '⚪');
            $grossSign = $this->formatMoney($stats['gross_pnl']);
            $feesSign = $this->formatMoney(-$stats['total_fees'], false);
            $netSign = $this->formatMoney($stats['net_pnl']);

            $lines[] = '📈 <b>Итоги дня:</b>';
            $lines[] = "• Всего закрыто сделок: <b>{$stats['total_closed']}</b>";
            $lines[] = "• Винрейт: <b>{$stats['win_rate']}%</b> (✅ {$stats['wins']} / ❌ {$stats['losses']}".
                ($stats['breakeven'] > 0 ? " / ⚪ {$stats['breakeven']}" : '').')';
            $lines[] = "• Грязный P&L: <code>{$grossSign}</code>";
            $lines[] = "• Комиссии и фандинг: <code>{$feesSign}</code>";
            $lines[] = "• 💰 <b>Чистый P&L: <code>{$netSign}</code> {$pnlEmoji}</b>";
            $lines[] = '';

            // Long / Short breakdown
            $lines[] = '⚖️ <b>По направлениям:</b>';
            if ($stats['long_count'] > 0) {
                $lNet = $this->formatMoney($stats['long_net']);
                $lines[] = "• 🟢 LONG: <b>{$stats['long_count']}</b> (WR: {$stats['long_win_rate']}%, Net: <code>{$lNet}</code>)";
            } else {
                $lines[] = '• 🟢 LONG: 0 сделок';
            }

            if ($stats['short_count'] > 0) {
                $sNet = $this->formatMoney($stats['short_net']);
                $lines[] = "• 🔴 SHORT: <b>{$stats['short_count']}</b> (WR: {$stats['short_win_rate']}%, Net: <code>{$sNet}</code>)";
            } else {
                $lines[] = '• 🔴 SHORT: 0 сделок';
            }
            $lines[] = '';

            // Best and worst trades
            if ($stats['best_trade'] instanceof Position && $stats['worst_trade'] instanceof Position && $stats['total_closed'] >= 2) {
                $bestNet = $this->formatMoney($stats['best_trade']->netPnl() ?? 0.0);
                $worstNet = $this->formatMoney($stats['worst_trade']->netPnl() ?? 0.0);
                $lines[] = "🏆 Лучшая: <b>#{$stats['best_trade']->symbol}</b> (<code>{$bestNet}</code>)";
                $lines[] = "⚠️ Худшая: <b>#{$stats['worst_trade']->symbol}</b> (<code>{$worstNet}</code>)";
                $lines[] = '';
            }

            // Closed positions list
            $lines[] = "📋 <b>Список сделок ({$stats['total_closed']}):</b>";
            foreach ($closed as $idx => $pos) {
                $num = $idx + 1;
                $pNet = $pos->netPnl() ?? 0.0;
                $itemEmoji = $pNet > 0 ? '🟢' : ($pNet < 0 ? '🔴' : '⚪');
                $netStr = $this->formatMoney($pNet);
                $grossStr = $this->formatMoney($pos->realized_pnl ?? 0.0);
                $commStr = $this->formatMoney(-($pos->commission ?? 0.0) - ($pos->funding_fee ?? 0.0), false);
                $entryStr = $this->formatPrice($pos->entry_price);
                $exitStr = $pos->exit_price > 0 ? $this->formatPrice($pos->exit_price) : '—';
                $exitReason = $this->formatExitReason($pos);
                $signal = $this->formatSignal($pos->signal_type);
                $duration = $this->formatDuration($pos);

                $lines[] = "{$num}. {$itemEmoji} <b>#{$pos->symbol} {$pos->direction}</b> [{$signal}]";
                $lines[] = "   ├ Вход: <code>{$entryStr}</code> → Выход: <code>{$exitStr}</code> ({$exitReason})";
                $lines[] = "   ├ Чистый: <b><code>{$netStr}</code></b> (P&L: <code>{$grossStr}</code>, Ком: <code>{$commStr}</code>)";
                $lines[] = "   └ Время: {$duration}";
            }
            $lines[] = '';
        }

        // Open positions
        if ($open->isNotEmpty()) {
            $lines[] = "⏳ <b>Открытые позиции сейчас ({$open->count()}):</b>";
            foreach ($open as $pos) {
                $dirEmoji = $pos->direction === Direction::Long->value ? '🟢' : '🔴';
                $entryStr = $this->formatPrice($pos->entry_price);
                $openTime = $pos->opened_at ? $pos->opened_at->copy()->setTimezone($tz)->format('H:i') : '—';
                $stopStr = $pos->stop_price > 0 ? $this->formatPrice($pos->stop_price) : '—';
                $tpStr = $pos->target1 > 0 ? $this->formatPrice($pos->target1) : '—';

                $lines[] = "• {$dirEmoji} <b>#{$pos->symbol} {$pos->direction}</b> ({$pos->interval})";
                $lines[] = "  Вход: <code>{$entryStr}</code> (открыта в {$openTime}, SL: <code>{$stopStr}</code>, TP: <code>{$tpStr}</code>)";
            }
        }

        $fullText = implode("\n", $lines);

        return $this->telegram->splitMessage($fullText, 4000);
    }

    public function formatMoney(float $amount, bool $forceSign = true): string
    {
        $sign = $amount > 0 ? '+' : '';
        $formatted = number_format($amount, 2, '.', '');

        if ($forceSign && $amount > 0) {
            return "+{$formatted} USDT";
        }

        return "{$formatted} USDT";
    }

    public function formatPrice(float $price): string
    {
        if ($price >= 100) {
            return number_format($price, 2, '.', ' ');
        }
        if ($price >= 1) {
            return number_format($price, 4, '.', ' ');
        }

        return rtrim(rtrim(number_format($price, 6, '.', ''), '0'), '.');
    }

    public function formatExitReason(Position $pos): string
    {
        if (! empty($pos->exit_type)) {
            return match ($pos->exit_type) {
                ExitType::Target1->value => 'TP1',
                ExitType::Target2->value => 'TP2',
                ExitType::StopLoss->value => 'SL',
                ExitType::EarlyReversal->value => 'Разворот',
                'MARKET' => 'Рынок',
                default => $pos->exit_type,
            };
        }

        return $pos->exit_reason ?? 'Закрыта';
    }

    public function formatSignal(?string $signalType): string
    {
        return match ($signalType) {
            SignalType::Bounce->value => 'BOUNCE',
            SignalType::Retest->value => 'RETEST',
            SignalType::FalseBreakout->value => 'FAKEOUT',
            SignalType::TrendPullback->value => 'PULLBACK',
            'LEAD_LAG' => 'LEAD_LAG',
            'EXTERNAL' => 'EXTERNAL',
            null => 'MANUAL',
            default => (string) $signalType,
        };
    }

    public function formatDuration(Position $pos): string
    {
        if ($pos->opened_at === null || $pos->closed_at === null) {
            return '—';
        }

        $tz = config('services.telegram.report_timezone', config('app.timezone', 'UTC'));
        $openStr = $pos->opened_at->copy()->setTimezone($tz)->format('H:i');
        $closeStr = $pos->closed_at->copy()->setTimezone($tz)->format('H:i');

        $diffMinutes = max(1, (int) round($pos->opened_at->diffInMinutes($pos->closed_at)));

        if ($diffMinutes < 60) {
            $durationStr = "{$diffMinutes}м";
        } else {
            $hours = intdiv($diffMinutes, 60);
            $mins = $diffMinutes % 60;
            $durationStr = $mins > 0 ? "{$hours}ч {$mins}м" : "{$hours}ч";
        }

        return "{$openStr} → {$closeStr} ({$durationStr})";
    }

    private function resolveDate(CarbonInterface|string|null $date, string $timezone): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date)->setTimezone($timezone);
        }

        if (is_string($date) && trim($date) !== '') {
            return Carbon::parse($date, $timezone);
        }

        return Carbon::now($timezone);
    }
}
