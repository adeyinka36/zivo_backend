<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class TestLogin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:login 
                            {email : The email address to login with}
                            {password : The password to login with}
                            {--url= : The base URL for the API (optional)}
                            {--show-token : Show the full token in the response}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the login API endpoint with email and password';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $baseUrl = $this->option('url') ?: Config::get('app.url');
        $showToken = $this->option('show-token');

        // Ensure the base URL doesn't end with a slash
        $baseUrl = rtrim($baseUrl, '/');
        $loginUrl = $baseUrl . '/api/v1/login';

        $this->info("Testing login API...");
        $this->line("URL: {$loginUrl}");
        $this->line("Email: {$email}");
        $this->line("Password: " . str_repeat('*', strlen($password)));
        $this->newLine();

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($loginUrl, [
                    'email' => $email,
                    'password' => $password,
                ]);

            $statusCode = $response->status();
            $responseData = $response->json();

            // Display response status
            if ($statusCode === 200) {
                $this->info("✅ Login successful! (Status: {$statusCode})");
            } else {
                $this->error("❌ Login failed! (Status: {$statusCode})");
            }

            $this->newLine();

            // Display response data
            if ($responseData) {
                $this->line("Response Data:");
                $this->line("=============");
                
                if (isset($responseData['message'])) {
                    $this->line("Message: " . $responseData['message']);
                }

                if (isset($responseData['data'])) {
                    $data = $responseData['data'];
                    
                    if (isset($data['user'])) {
                        $user = $data['user'];
                        $this->line("User ID: " . ($user['id'] ?? 'N/A'));
                        $this->line("Name: " . ($user['name'] ?? 'N/A'));
                        $this->line("Username: " . ($user['username'] ?? 'N/A'));
                        $this->line("Email: " . ($user['email'] ?? 'N/A'));
                        $this->line("Email Verified: " . (isset($user['email_verified_at']) && $user['email_verified_at'] ? 'Yes' : 'No'));
                    }

                    if (isset($data['token'])) {
                        $token = $data['token'];
                        if ($showToken) {
                            $this->line("Token: " . $token);
                        } else {
                            $this->line("Token: " . substr($token, 0, 20) . "... (use --show-token to see full token)");
                        }
                    }
                }

                // Display any errors
                if (isset($responseData['errors'])) {
                    $this->error("Validation Errors:");
                    foreach ($responseData['errors'] as $field => $messages) {
                        $this->line("  {$field}: " . implode(', ', $messages));
                    }
                }
            } else {
                $this->warn("No response data received");
                $this->line("Raw response: " . $response->body());
            }

        } catch (\Exception $e) {
            $this->error("❌ Request failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
