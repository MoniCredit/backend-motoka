<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\Car;
use App\Models\DriverLicense;
use App\Models\Plate;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ProcessReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all due reminders and create notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing reminders...');

        // Get all due reminders
        $reminders = Reminder::where('is_sent', false)
            ->where('remind_at', '<=', now())
            ->get();

        $notificationsCreated = 0;

        foreach ($reminders as $reminder) {
            $this->processReminder($reminder);
            $notificationsCreated++;
        }

        $this->info("Processed {$notificationsCreated} reminders and created notifications");

        // Clean up old notifications
        $cleanedUp = NotificationService::cleanupOldNotifications(30);
        $this->info("Cleaned up {$cleanedUp} old notifications");

        return Command::SUCCESS;
    }

    private function processReminder($reminder)
    {
        $item = null;
        $reminderType = '';

        switch ($reminder->type) {
            case 'car':
                $item = Car::find($reminder->ref_id);
                $reminderType = 'car_expiry';
                break;
            case 'driver_license':
                $item = DriverLicense::find($reminder->ref_id);
                $reminderType = 'license_expiry';
                break;
            case 'plate_number':
                $item = Plate::find($reminder->ref_id);
                $reminderType = 'plate_expiry';
                break;
        }

        if ($item) {
            $daysLeft = 0;
            
            // Calculate days left based on item type
            if ($reminder->type === 'car' && $item->expiry_date) {
                $daysLeft = Carbon::now()->diffInDays(Carbon::parse($item->expiry_date), false);
            } elseif ($reminder->type === 'driver_license' && $item->expiry_date) {
                $daysLeft = Carbon::now()->diffInDays(Carbon::parse($item->expiry_date), false);
            } elseif ($reminder->type === 'plate_number' && $item->expiry_date) {
                $daysLeft = Carbon::now()->diffInDays(Carbon::parse($item->expiry_date), false);
            }

            // Create notification
            NotificationService::notifyReminder(
                $reminder->user_id,
                $reminder->type,
                $item,
                $reminderType,
                $daysLeft
            );

            // Mark reminder as sent
            $reminder->update(['is_sent' => true]);
        }
    }
}