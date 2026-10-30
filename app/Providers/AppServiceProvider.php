<?php

namespace App\Providers;

use App\Models\DigitalAsset;
use App\Models\PaymentAttempt;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Policies\CatalogPolicy;
use App\Policies\OrderPolicy;
use App\Shipping\StoreShippingModifier;
use App\Storefront\GuestCart;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Horizon\Horizon;
use Lunar\Base\ShippingModifiers;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Customer authentication will use its own guard. Until then, do not expose
        // a password-only Fortify route that bypasses the admin panel's MFA flow.
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('storefront.layout', function (\Illuminate\View\View $view): void {
            $quantity = request()->hasSession() ? app(GuestCart::class)->itemCount(request()->session()) : 0;
            $view->with('cartQuantity', $quantity);
        });

        // Explicitly provisioned super administrators have every application ability.
        Gate::before(fn (User $user): ?bool => $user->is_super_admin ? true : null);

        Gate::policy(Product::class, CatalogPolicy::class);
        Gate::policy(DigitalAsset::class, CatalogPolicy::class);
        Gate::policy(ShippingMethod::class, CatalogPolicy::class);
        app(ShippingModifiers::class)->add(StoreShippingModifier::class);
        config(['lunar.orders.statuses.demo-cancelled' => ['label' => 'Testna narudžba otkazana'],
            'lunar.orders.statuses.demo-expired' => ['label' => 'Testna narudžba istekla']]);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(PaymentAttempt::class, OrderPolicy::class);
        Gate::policy(OrderLine::class, OrderPolicy::class);
        Gate::policy(Transaction::class, OrderPolicy::class);
        Gate::policy(Activity::class, OrderPolicy::class);
        config(['lunar.orders.statuses.payment-pending' => ['label' => 'Čeka potvrdu plaćanja'],
            'lunar.orders.statuses.payment-review' => ['label' => 'Plaćanje zahtijeva provjeru'],
            'lunar.orders.statuses.payment-expired' => ['label' => 'Procesor potvrdio istek']]);
        Gate::policy(Collection::class, CatalogPolicy::class);
        Gate::policy(CollectionGroup::class, CatalogPolicy::class);
        Gate::policy(ProductVariant::class, CatalogPolicy::class);
        Gate::policy(ProductOption::class, CatalogPolicy::class);
        Gate::policy(ProductOptionValue::class, CatalogPolicy::class);

        RateLimiter::for('storefront-search', function (Request $request): Limit {
            return $request->hasAny(['q', 'available', 'page']) || $request->expectsJson()
                ? Limit::perMinute(45)->by((string) $request->ip()) : Limit::none();
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id', $request->ip()));
        });

        // Super administrators explicitly use password-only login. Other admins
        // must enroll MFA before they can access this operational dashboard.
        // This relies on Filament being the only authentication entry point;
        // customer authentication must use a separate guard when it is introduced.
        if (! class_exists(Horizon::class)) {
            return;
        }

        Horizon::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User
                && ($user->is_super_admin || ($user->is_admin && filled($user->getAppAuthenticationSecret())));
        });
    }
}
