# ========================================
# AIRNAV Backend - Auto Setup Script
# ========================================
# Usage: .\setup.ps1
# ========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  AIRNAV Backend - Auto Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin = $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "⚠️  Warning: Not running as Administrator" -ForegroundColor Yellow
    Write-Host "   Some operations may fail if PostgreSQL service needs to be started" -ForegroundColor Yellow
    Write-Host ""
}

# Step 1: Check PHP
Write-Host "🔍 Checking PHP..." -ForegroundColor Yellow
try {
    $phpVersion = php -v
    Write-Host "✅ PHP installed" -ForegroundColor Green
    Write-Host $phpVersion[0] -ForegroundColor Gray
} catch {
    Write-Host "❌ PHP not found! Please install PHP 8.2+" -ForegroundColor Red
    exit 1
}

# Step 2: Check Composer
Write-Host ""
Write-Host "🔍 Checking Composer..." -ForegroundColor Yellow
try {
    $composerVersion = composer -V
    Write-Host "✅ Composer installed" -ForegroundColor Green
    Write-Host $composerVersion -ForegroundColor Gray
} catch {
    Write-Host "❌ Composer not found! Please install Composer" -ForegroundColor Red
    exit 1
}

# Step 3: Check PostgreSQL
Write-Host ""
Write-Host "🔍 Checking PostgreSQL..." -ForegroundColor Yellow
try {
    $psqlVersion = psql --version
    Write-Host "✅ PostgreSQL installed" -ForegroundColor Green
    Write-Host $psqlVersion -ForegroundColor Gray
} catch {
    Write-Host "⚠️  PostgreSQL not found in PATH" -ForegroundColor Yellow
    Write-Host "   Make sure PostgreSQL is installed and service is running" -ForegroundColor Yellow
}

# Step 4: Install Composer Dependencies
Write-Host ""
Write-Host "📦 Installing Composer dependencies..." -ForegroundColor Yellow
composer install --no-interaction
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Composer dependencies installed" -ForegroundColor Green
} else {
    Write-Host "❌ Failed to install dependencies" -ForegroundColor Red
    exit 1
}

# Step 5: Install Laravel Sanctum
Write-Host ""
Write-Host "🔐 Installing Laravel Sanctum..." -ForegroundColor Yellow
composer require laravel/sanctum --no-interaction
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Laravel Sanctum installed" -ForegroundColor Green
} else {
    Write-Host "❌ Failed to install Sanctum" -ForegroundColor Red
    exit 1
}

# Step 6: Publish Sanctum configuration
Write-Host ""
Write-Host "📝 Publishing Sanctum configuration..." -ForegroundColor Yellow
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --force
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Sanctum configuration published" -ForegroundColor Green
} else {
    Write-Host "⚠️  Warning: Could not publish Sanctum config" -ForegroundColor Yellow
}

# Step 7: Check .env file
Write-Host ""
Write-Host "🔍 Checking .env file..." -ForegroundColor Yellow
if (Test-Path ".env") {
    Write-Host "✅ .env file exists" -ForegroundColor Green
} else {
    Write-Host "⚠️  .env file not found, copying from .env.example..." -ForegroundColor Yellow
    if (Test-Path ".env.example") {
        Copy-Item ".env.example" ".env"
        Write-Host "✅ .env file created" -ForegroundColor Green
    } else {
        Write-Host "❌ .env.example not found!" -ForegroundColor Red
        exit 1
    }
}

# Step 8: Generate application key
Write-Host ""
Write-Host "🔑 Generating application key..." -ForegroundColor Yellow
php artisan key:generate --force
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Application key generated" -ForegroundColor Green
} else {
    Write-Host "❌ Failed to generate key" -ForegroundColor Red
    exit 1
}

# Step 9: Database setup
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  DATABASE SETUP" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Current .env database configuration:" -ForegroundColor Yellow

# Read database config from .env
$dbConfig = Get-Content ".env" | Select-String -Pattern "^DB_"
foreach ($line in $dbConfig) {
    if ($line -match "DB_PASSWORD") {
        Write-Host "DB_PASSWORD=***hidden***" -ForegroundColor Gray
    } else {
        Write-Host $line -ForegroundColor Gray
    }
}

Write-Host ""
$createDb = Read-Host "Do you want to create database 'airnav' now? (y/n)"

if ($createDb -eq "y" -or $createDb -eq "Y") {
    Write-Host ""
    $dbUser = Read-Host "PostgreSQL username (default: postgres)"
    if ([string]::IsNullOrWhiteSpace($dbUser)) {
        $dbUser = "postgres"
    }
    
    Write-Host "Creating database 'airnav'..." -ForegroundColor Yellow
    $env:PGPASSWORD = Read-Host "PostgreSQL password" -AsSecureString | ConvertFrom-SecureString
    
    try {
        psql -U $dbUser -c "CREATE DATABASE airnav;" 2>&1 | Out-Null
        Write-Host "✅ Database 'airnav' created successfully" -ForegroundColor Green
    } catch {
        Write-Host "⚠️  Database may already exist or creation failed" -ForegroundColor Yellow
        Write-Host "   Error: $_" -ForegroundColor Gray
    }
    
    # Update .env with password
    Write-Host ""
    $updateEnv = Read-Host "Update .env with this password? (y/n)"
    if ($updateEnv -eq "y" -or $updateEnv -eq "Y") {
        $plainPassword = Read-Host "Enter password again"
        $envContent = Get-Content ".env"
        $envContent = $envContent -replace "DB_PASSWORD=.*", "DB_PASSWORD=$plainPassword"
        $envContent | Set-Content ".env"
        Write-Host "✅ .env updated" -ForegroundColor Green
    }
}

# Step 10: Run migrations
Write-Host ""
Write-Host "📊 Running database migrations..." -ForegroundColor Yellow
$runMigrations = Read-Host "Run migrations now? (y/n)"

if ($runMigrations -eq "y" -or $runMigrations -eq "Y") {
    php artisan migrate
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✅ Migrations completed successfully" -ForegroundColor Green
        
        # Step 11: Run seeders
        Write-Host ""
        Write-Host "🌱 Running database seeders..." -ForegroundColor Yellow
        $runSeeders = Read-Host "Seed default data (shifts and admin)? (y/n)"
        
        if ($runSeeders -eq "y" -or $runSeeders -eq "Y") {
            php artisan db:seed
            if ($LASTEXITCODE -eq 0) {
                Write-Host "✅ Database seeded successfully" -ForegroundColor Green
            } else {
                Write-Host "⚠️  Seeding failed" -ForegroundColor Yellow
            }
        }
    } else {
        Write-Host "❌ Migration failed" -ForegroundColor Red
        Write-Host "   Please check database connection in .env" -ForegroundColor Yellow
    }
}

# Final summary
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  SETUP COMPLETE!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "✅ Laravel backend is ready!" -ForegroundColor Green
Write-Host ""
Write-Host "📝 Default Admin Credentials:" -ForegroundColor Yellow
Write-Host "   Email: admin@airnav.com" -ForegroundColor White
Write-Host "   Password: admin123" -ForegroundColor White
Write-Host ""
Write-Host "🚀 Start the server with:" -ForegroundColor Yellow
Write-Host "   php artisan serve" -ForegroundColor White
Write-Host ""
Write-Host "📡 API will be available at:" -ForegroundColor Yellow
Write-Host "   http://127.0.0.1:8000/api" -ForegroundColor White
Write-Host ""
Write-Host "📖 Read documentation:" -ForegroundColor Yellow
Write-Host "   - AIRNAV_README.md" -ForegroundColor White
Write-Host "   - SETUP_INSTRUCTIONS.md" -ForegroundColor White
Write-Host ""

$startServer = Read-Host "Start development server now? (y/n)"
if ($startServer -eq "y" -or $startServer -eq "Y") {
    Write-Host ""
    Write-Host "🚀 Starting Laravel development server..." -ForegroundColor Green
    Write-Host "   Press Ctrl+C to stop" -ForegroundColor Gray
    Write-Host ""
    php artisan serve
}
