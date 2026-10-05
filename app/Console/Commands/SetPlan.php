<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Limits;
use Illuminate\Console\Command;

class SetPlan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plan:set {email : The account to change} {plan? : A plan from config/plans.php; leave out to just show the current one}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Show or change an account's plan (free, pro…), which sets their limits";

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No account with the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $plan = $this->argument('plan');
        if ($plan !== null) {
            $plans = array_keys(config('plans.plans'));
            if (! in_array($plan, $plans, true)) {
                $this->error("Unknown plan \"{$plan}\". Choose one of: ".implode(', ', $plans).'.');

                return self::FAILURE;
            }

            $user->forceFill(['plan' => $plan])->save();
            $this->info("{$user->email} is now on the {$plan} plan.");
        }

        $this->table(
            ['Limit', 'Used', 'Allowed'],
            collect(Limits::plan($user)['limits'])
                ->map(fn (int $limit, string $key) => [$key, Limits::usage($user, $key), $limit])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
