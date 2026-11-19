@php
    use App\Enums\DisputeStatus;
    use App\Enums\OrderStatus;

    $dispute = $order->latestDispute;
    $shipment = $order->shipment;
@endphp

<div>
    <x-account.shell :title="__('Order :number', ['number' => $order->number])">
        <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
            <div class="min-w-0 space-y-6">
                {{-- Where the order stands and what the buyer can do next. --}}
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <x-order.status :status="$order->status" />
                            <p class="mt-3 text-sm text-slate-700 dark:text-slate-300">
                                @switch($order->status)
                                    @case(OrderStatus::InEscrow)
                                        @if (blank($order->shipping_address))
                                            {{ __('Your payment is safe in escrow. Add your delivery address so the seller can ship.') }}
                                        @else
                                            {{ __('Your payment is safe in escrow until the item arrives. If the seller does not ship by') }}
                                            <x-datetime :value="$order->ship_by_at" format="j M Y" class="font-medium" />
                                            {{ __('the order is cancelled and you are refunded in full.') }}
                                        @endif
                                        @break
                                    @case(OrderStatus::Shipped)
                                        {{ __('The item is on its way. Confirm when it arrives. If you report no problem by') }}
                                        <x-datetime :value="$order->auto_release_at" format="j M Y" class="font-medium" />
                                        {{ __('the payment goes to the seller.') }}
                                        @break
                                    @case(OrderStatus::Disputed)
                                        {{ __('You reported a problem. The payment stays in escrow while our team looks into it.') }}
                                        @break
                                    @case(OrderStatus::Completed)
                                        {{ __('Done. The payment was released to the seller.') }}
                                        @break
                                    @case(OrderStatus::Cancelled)
                                        {{ $order->cancellation_reason }} {{ __('The full amount was refunded to your wallet.') }}
                                        @break
                                    @default
                                        {{ __(':amount was refunded to your wallet.', ['amount' => $order->refunded_amount->format()]) }}
                                @endswitch
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($can['confirm'])
                                <x-button type="button" wire:click="confirmDelivery"
                                          wire:confirm="{{ __('Confirm the item arrived as described? The payment is released to the seller and this cannot be undone.') }}">
                                    <x-app-icon name="check" class="size-4" /> {{ __('I received it') }}
                                </x-button>
                            @endif
                            @if ($can['dispute'] && ! $disputing)
                                <x-button type="button" variant="secondary" wire:click="$set('disputing', true)">{{ __('Report a problem') }}</x-button>
                            @endif
                            @if ($can['withdraw'])
                                <x-button type="button" variant="secondary" wire:click="withdrawDispute"
                                          wire:confirm="{{ __('Withdraw your dispute? The order goes back to shipped and the payment is released on the usual date.') }}">
                                    {{ __('Withdraw dispute') }}
                                </x-button>
                            @endif
                        </div>
                    </div>

                    @if ($disputing && $can['dispute'])
                        <form wire:submit="openDispute" class="mt-6 space-y-4 border-t border-slate-100 pt-6 dark:border-slate-800">
                            <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Report a problem') }}</h2>
                            <div>
                                <label for="reason" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('What is wrong?') }}</label>
                                <select id="reason" wire:model="reason" class="form-control mt-1.5">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($reasons as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                                @error('reason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="description" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Tell us what happened') }}</label>
                                <textarea id="description" wire:model="description" rows="4" class="form-control mt-1.5 h-24 resize-none"></textarea>
                                @error('description') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex gap-2">
                                <x-button>{{ __('Open dispute') }}</x-button>
                                <x-button type="button" variant="ghost" wire:click="$set('disputing', false)">{{ __('Cancel') }}</x-button>
                            </div>
                        </form>
                    @endif

                    @if ($dispute && $dispute->status !== DisputeStatus::Withdrawn)
                        <div class="mt-6 rounded-md bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                            <p class="font-medium text-slate-900 dark:text-slate-50">{{ __('Dispute') }}: {{ $dispute->reason->label() }}</p>
                            <p class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-400">{{ $dispute->description }}</p>
                            @if ($dispute->status === DisputeStatus::Resolved)
                                <p class="mt-3 font-medium text-slate-900 dark:text-slate-50">{{ $dispute->resolution?->label() }}</p>
                                <p class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-400">{{ $dispute->resolution_note }}</p>
                            @endif
                        </div>
                    @endif
                </x-card>

                {{-- Review --}}
                @if ($order->review)
                    <x-card>
                        <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Your review') }}</h2>
                        <p class="mt-2 flex gap-0.5 text-amber-500" aria-label="{{ trans_choice(':count star|:count stars', $order->review->rating) }}">
                            @for ($i = 1; $i <= 5; $i++)
                                <x-app-icon name="star" @class(['size-4', 'fill-current' => $i <= $order->review->rating]) />
                            @endfor
                        </p>
                        @if ($order->review->comment)
                            <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $order->review->comment }}</p>
                        @endif
                        @if ($order->review->reply)
                            <div class="mt-4 border-l-2 border-slate-200 pl-3 text-sm dark:border-slate-700">
                                <p class="font-medium text-slate-900 dark:text-slate-50">{{ __('Reply from :seller', ['seller' => $order->company->name]) }}</p>
                                <p class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-400">{{ $order->review->reply }}</p>
                            </div>
                        @endif
                    </x-card>
                @elseif ($can['review'])
                    <x-card>
                        <form wire:submit="leaveReview" class="space-y-4">
                            <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Rate :seller', ['seller' => $order->company->name]) }}</h2>
                            <fieldset>
                                <legend class="sr-only">{{ __('Rating') }}</legend>
                                <div class="flex gap-1">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <label class="cursor-pointer">
                                            <input type="radio" value="{{ $i }}" wire:model.live="rating" class="sr-only">
                                            <x-app-icon name="star" @class(['size-7', 'fill-amber-400 text-amber-500' => $i <= $rating, 'text-slate-300 dark:text-slate-600' => $i > $rating]) />
                                            <span class="sr-only">{{ trans_choice(':count star|:count stars', $i) }}</span>
                                        </label>
                                    @endfor
                                </div>
                                @error('rating') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </fieldset>
                            <div>
                                <label for="comment" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Comment (optional)') }}</label>
                                <textarea id="comment" wire:model="comment" rows="3" class="form-control mt-1.5 h-24 resize-none"></textarea>
                                @error('comment') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <x-button>{{ __('Post review') }}</x-button>
                        </form>
                    </x-card>
                @endif

                {{-- Chat with the seller --}}
                <x-card>
                    <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Messages with :seller', ['seller' => $order->company?->name]) }}</h2>
                    <div class="mt-4">
                        <livewire:messages.order-chat :order-id="$order->id" :key="'chat-'.$order->id" />
                    </div>
                </x-card>

                {{-- Timeline --}}
                <x-card>
                    <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('History') }}</h2>
                    <ol class="mt-4 space-y-4">
                        @foreach ($order->events->reverse() as $event)
                            <li class="flex gap-3 text-sm" wire:key="event-{{ $event->id }}">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-500"></span>
                                <div>
                                    <p class="text-slate-800 dark:text-slate-200">{{ $event->description }}</p>
                                    <x-datetime :value="$event->created_at" class="text-xs text-slate-500 dark:text-slate-400" />
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-card>
            </div>

            <div class="space-y-6">
                <x-card>
                    <a href="{{ route('auctions.show', $order->auction_id) }}" class="flex gap-3">
                        <span class="size-16 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                            <x-product-image :image="$order->auction?->product?->coverImage" :seed="$order->auction_id" :alt="$order->auction?->title" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-900 hover:text-brand-700 dark:text-slate-50 dark:hover:text-brand-300">{{ $order->auction?->title }}</span>
                            <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $order->company->name }}</span>
                        </span>
                    </a>
                    <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                        <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">{{ __('Price') }}</dt><dd><x-money :amount="$order->amount" class="font-semibold" /></dd></div>
                        @if ($order->refunded_amount->isPositive())
                            <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">{{ __('Refunded') }}</dt><dd><x-money :amount="$order->refunded_amount" /></dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">{{ __('Ordered') }}</dt><dd><x-datetime :value="$order->created_at" format="j M Y" /></dd></div>
                    </dl>
                    @if ($receipt)
                        <a href="{{ route('invoices.pdf', $receipt) }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">
                            <x-app-icon name="download" class="size-4" /> {{ __('Purchase receipt :number', ['number' => $receipt->number]) }}
                        </a>
                    @endif
                </x-card>

                <x-card>
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Delivery address') }}</h2>
                        @if ($can['address'] && ! $editingAddress)
                            <button type="button" wire:click="$set('editingAddress', true)" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">{{ __('Change') }}</button>
                        @endif
                    </div>

                    @if ($editingAddress && $can['address'])
                        <form wire:submit="saveAddress" class="mt-4 space-y-4">
                            <x-field :label="__('Name')" name="shipping_name" wire:model="shipping_name" autocomplete="name" />
                            <x-field :label="__('Phone')" name="shipping_phone" type="tel" wire:model="shipping_phone" autocomplete="tel" />
                            <div>
                                <label for="shipping_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Address') }}</label>
                                <textarea id="shipping_address" wire:model="shipping_address" rows="3" autocomplete="street-address" class="form-control mt-1.5 h-24 resize-none"></textarea>
                                @error('shipping_address') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <x-button>{{ __('Save address') }}</x-button>
                        </form>
                    @elseif (filled($order->shipping_address))
                        <address class="mt-3 text-sm not-italic text-slate-700 dark:text-slate-300">
                            {{ $order->shipping_name }}<br>
                            {{ $order->shipping_phone }}<br>
                            <span class="whitespace-pre-line">{{ $order->shipping_address }}</span>
                        </address>
                    @else
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('No address was given.') }}</p>
                    @endif
                </x-card>

                @if ($shipment)
                    <x-card>
                        <h2 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-slate-50"><x-app-icon name="truck" class="size-5" /> {{ __('Shipment') }}</h2>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Courier') }}</dt><dd class="text-right">{{ $shipment->courier }}</dd></div>
                            @if ($shipment->tracking_number)
                                <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Tracking') }}</dt>
                                    <dd class="text-right">
                                        @if ($shipment->tracking_url)
                                            <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener noreferrer nofollow" class="text-brand-700 hover:underline dark:text-brand-300">{{ $shipment->tracking_number }}</a>
                                        @else
                                            {{ $shipment->tracking_number }}
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Shipped') }}</dt><dd><x-datetime :value="$shipment->shipped_at" format="j M Y" /></dd></div>
                            @if ($shipment->delivered_at)
                                <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Delivered') }}</dt><dd><x-datetime :value="$shipment->delivered_at" format="j M Y" /></dd></div>
                            @endif
                        </dl>
                        @if ($shipment->note)
                            <p class="mt-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-400">{{ $shipment->note }}</p>
                        @endif
                    </x-card>
                @endif
            </div>
        </div>
    </x-account.shell>
</div>
