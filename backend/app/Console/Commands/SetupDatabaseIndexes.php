<?php
// backend/app/Console/Commands/SetupDatabaseIndexes.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupDatabaseIndexes extends Command
{
    protected $signature = 'db:setup-indexes';
    protected $description = 'Setup MongoDB indexes for optimal performance';

    public function handle()
    {
        $this->info('Setting up database indexes...');
        
        try {
            $mongodb = DB::connection('mongodb')->getMongoDB();
            
            // Post logs indexes
            try {
                $mongodb->selectCollection('post_logs')->createIndex(
                    ['post_id' => 1, 'group_id' => 1, 'scheduled_time' => 1],
                    ['unique' => true, 'name' => 'post_group_time_unique']
                );
                $this->info('✅ Created post_logs unique index');
            } catch (\Exception $e) {
                $this->warn('Post logs unique index may already exist: ' . $e->getMessage());
            }
            
            try {
                $mongodb->selectCollection('post_logs')->createIndex(
                    ['status' => 1, 'sent_at' => -1],
                    ['name' => 'status_sent_at']
                );
                $this->info('✅ Created post_logs status index');
            } catch (\Exception $e) {
                $this->warn('Post logs status index may already exist');
            }
            
            // Scheduled posts indexes
            try {
                $mongodb->selectCollection('scheduled_posts')->createIndex(
                    ['status' => 1, 'schedule_times_utc' => 1],
                    ['name' => 'status_schedule_times']
                );
                $this->info('✅ Created scheduled_posts index');
            } catch (\Exception $e) {
                $this->warn('Scheduled posts index may already exist');
            }
            
            // Users indexes
            try {
                $mongodb->selectCollection('users')->createIndex(
                    ['telegram_id' => 1],
                    ['unique' => true, 'name' => 'telegram_id_unique']
                );
                $this->info('✅ Created users telegram_id index');
            } catch (\Exception $e) {
                $this->warn('Users telegram_id index may already exist');
            }
            
            // Groups indexes
            try {
                $mongodb->selectCollection('groups')->createIndex(
                    ['telegram_id' => 1],
                    ['unique' => true, 'name' => 'telegram_id_unique']
                );
                $this->info('✅ Created groups telegram_id index');
            } catch (\Exception $e) {
                $this->warn('Groups telegram_id index may already exist');
            }
            
            $this->info('✅ Database indexes setup completed!');
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to create indexes: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}