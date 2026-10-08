<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Bot\BotMessenger;
use App\Services\Budgets\BudgetManager;
use App\Services\Recurring\RecurringManager;
use App\TransactionType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Telegram\Bot\Exceptions\TelegramSDKException;

#[Signature('recurring:run')]
#[Description('Record transactions for due recurring entries and notify their users')]
class RecurringTransactionsCommand extends Command
{
    public function __construct(
        private readonly BotMessenger $bot,
        private readonly RecurringManager $recurring,
        private readonly BudgetManager $budgets,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Records every recurring entry that is due today and notifies the
     * user. A failure for a single entry is logged and does not stop the
     * rest of the run.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $recorded = 0;

        foreach (User::all() as $user) {
            foreach ($this->recurring->dueOn($user, $now) as $entry) {
                try {
                    $transaction = $this->recurring->run($entry, $now);

                    $this->notify($user, $transaction);

                    $recorded++;
                } catch (TelegramSDKException $exception) {
                    $this->error(sprintf('Failed to record the recurring entry %s for %s (%d): %s', $entry->name, $user->name, $user->telegram_id, $exception->getMessage()));
                }
            }
        }

        $this->info(sprintf('Recorded %d recurring transactions.', $recorded));

        return self::SUCCESS;
    }

    /**
     * @throws TelegramSDKException
     */
    private function notify(User $user, Transaction $transaction): void
    {
        $direction = $transaction->type === TransactionType::Income ? 'доход' : 'расход';

        $this->bot->sendMessage(
            $user->telegram_id,
            sprintf('🔁 %s: %s %s (%s).', $transaction->comment, number_format((float) $transaction->amount, 2, '.', ''), $user->currency->value, $direction),
        );

        $alert = $this->budgets->alertAfterTransaction($user, $transaction);

        if ($alert !== null) {
            $this->bot->sendMessage($user->telegram_id, $alert);
        }
    }
}
