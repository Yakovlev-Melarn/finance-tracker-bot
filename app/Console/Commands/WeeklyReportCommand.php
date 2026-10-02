<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\Services\Reports\ReportBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Telegram\Bot\Exceptions\TelegramSDKException;

#[Signature('reports:weekly')]
#[Description('Send every user a summary of the last 7 days (income, expense, top categories)')]
class WeeklyReportCommand extends Command
{
    public function __construct(
        private readonly BotMessenger $bot,
        private readonly ReportBuilder $reports,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Sends the report to every registered user. A failure for a single
     * user is logged and does not stop the rest of the report.
     */
    public function handle(): int
    {
        $users = User::all();

        $sent = 0;

        foreach ($users as $user) {
            try {
                $stats = $this->reports->weekStats($user);

                $this->bot->sendMessage(
                    $user->telegram_id,
                    $this->reports->formatWeekStats($stats, $user->currency),
                    'Markdown',
                );

                $sent++;
            } catch (TelegramSDKException $exception) {
                $this->error(sprintf('Failed to send the weekly report to %s (%d): %s', $user->name, $user->telegram_id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('Sent %d of %d weekly reports.', $sent, $users->count()));

        return self::SUCCESS;
    }
}
