<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f6f8; color: #17212b; }
        .mcp-card { background: #fff; border: 1px solid #d7dce1; border-radius: 12px; max-width: 480px; margin: 8vh auto; padding: 28px; }
        .mcp-card button { border-radius: 6px; cursor: pointer; padding: 10px 14px; }
    </style>
</head>
<body class="font-sans antialiased bg-background text-foreground">
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Card Container -->
        <div class="rounded-lg border bg-card text-card-foreground shadow-sm mcp-card">
            <!-- Header -->
            <div class="flex flex-col space-y-1.5 p-6">
                <div class="flex items-center justify-center mb-4">
                    @if ($client->logo_uri ?? null)
                    <img src="{{ $client->logo_uri }}" alt="{{ $client->name }}" class="h-12 w-12 rounded object-contain">
                    @else
                    <!-- Shield Icon -->
                    <svg class="h-12 w-12 text-primary" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    @endif
                </div>

                <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
                    Authorize {{ $client->name }}
                </h3>

                <p class="text-sm text-muted-foreground text-center">
                    This application will be able to:<br/>Use available MCP functionality.
                </p>

                @if ($client->client_uri ?? null)
                <a href="{{ $client->client_uri }}" target="_blank" rel="noopener noreferrer" class="text-sm text-primary underline text-center">
                    {{ $client->client_uri }}
                </a>
                @endif
            </div>

            <!-- Content -->
            <div class="p-6 pt-0 space-y-4">
                <!-- User Info -->
                <div class="rounded-lg border p-4 bg-muted/50">
                    <p class="text-sm text-muted-foreground mb-2">Logged in as:</p>
                    <p class="font-medium">{{ $user->email }}</p>
                </div>

                <!-- Scopes / Permissions -->
                @if(count($scopes) > 0)
                    <div class="space-y-2">
                        <p class="text-sm font-medium">Permissions:</p>

                        <ul class="space-y-2">
                            @foreach($scopes as $scope)
                                <li class="flex items-start gap-2">
                                    <div class="rounded-full bg-primary/10 p-1 mt-0.5">
                                        <div class="h-1.5 w-1.5 rounded-full bg-primary"></div>
                                    </div>
                                    <span class="text-sm text-muted-foreground">
                                        {{ $scope->description }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <!-- Footer With Buttons -->
            <div class="flex items-center p-6 pt-0 gap-3">
                <!-- Deny Form -->
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 w-full">
                        <svg class="mr-2 h-4 w-4" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Cancel
                    </button>
                </form>

                <!-- Approve Form -->
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 w-full">
                        Authorize
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
