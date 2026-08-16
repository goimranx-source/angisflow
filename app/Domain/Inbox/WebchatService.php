<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Inbox\Models\Message;
use App\Domain\Inbox\Models\WebchatSession;
use App\Domain\Inbox\Models\WebchatWidget;
use App\Domain\Sales\CustomerDirectory;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The visitor's side of the chat widget.
 *
 * ── Everything here runs unauthenticated, from the open internet ─────────────
 *
 * That single fact shapes every method. There is no session, no signed-in user,
 * and no tenant resolved by middleware — the widget key names the business, so
 * tenancy has to be established from the request itself and then trusted for
 * nothing else.
 *
 * Two rules follow, and they are the whole security model:
 *
 *   The widget key may only start things. It is in the page source; treat it as
 *   published. It identifies a business and opens a conversation, and that is
 *   the complete list.
 *
 *   The visitor token may only touch its own conversation. Minted per session,
 *   stored hashed, and every read is scoped by it. A key lifted from a page's
 *   source is worth a new empty conversation and nothing else.
 *
 * ── Visitors are not customers ───────────────────────────────────────────────
 *
 * Somebody browsing a shop at midnight is a visitor. Most never buy, and
 * writing each one into the customer table makes every customer figure —
 * lifetime value, conversion rate, order count — meaningless. A visitor becomes
 * a customer when they give a real identity, and not before.
 */
final class WebchatService
{
    /** How long a quiet session stays resumable. */
    private const SESSION_DAYS = 30;

    public function __construct(
        private readonly InboxService $inbox,
        private readonly CustomerDirectory $customers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * What the widget needs to draw itself.
     *
     * @return array{widget: WebchatWidget, config: array<string, mixed>}
     *
     * @throws RuntimeException if the key is unknown, disabled, or the page may not embed it
     */
    public function boot(string $widgetKey, ?string $origin): array
    {
        $widget = $this->widgetFor($widgetKey, $origin);

        return ['widget' => $widget, 'config' => $widget->toPublicPayload()];
    }

    /**
     * Begin, or resume.
     *
     * A visitor who returns tomorrow with the same browser key continues the
     * same conversation. Starting again would mean an agent answering somebody
     * whose question is on a thread they cannot see.
     *
     * @return array{session: WebchatSession, token: ?string, conversation: Conversation}
     */
    public function start(string $widgetKey, ?string $origin, array $context = []): array
    {
        $widget = $this->widgetFor($widgetKey, $origin);
        $this->enterTenancy($widget);

        $visitorKey = $context['visitor_key'] ?? null;

        if ($visitorKey !== null) {
            $existing = WebchatSession::query()
                ->where('webchat_widget_id', $widget->id)
                ->where('visitor_key', $visitorKey)
                ->whereNotNull('conversation_id')
                ->latest('id')
                ->first();

            if ($existing !== null && $existing->isLive()) {
                $existing->forceFill([
                    'last_seen_at' => now(),
                    'page_url' => $context['page_url'] ?? $existing->page_url,
                ])->save();

                // No new token: the browser already holds one, and minting a
                // second would leave the first valid and unaccounted for.
                return [
                    'session' => $existing,
                    'token' => null,
                    'conversation' => $existing->conversation,
                ];
            }
        }

        return DB::transaction(function () use ($widget, $context, $visitorKey) {
            // The handle is the session's own id rather than anything the
            // visitor supplied. A conversation keyed on a visitor-controlled
            // value is one a visitor can aim at somebody else's thread.
            $handle = 'web_'.Str::lower((string) Str::ulid());

            $conversation = $this->inbox->threadFor($widget->channel, $handle, [
                'contact_name' => $context['name'] ?? null,
            ]);

            $token = Str::random(48);

            $session = WebchatSession::create([
                'webchat_widget_id' => $widget->id,
                'conversation_id' => $conversation->id,
                'token_hash' => WebchatSession::hash($token),
                'visitor_key' => $visitorKey ?? (string) Str::ulid(),
                'name' => $context['name'] ?? null,
                'email' => $context['email'] ?? null,
                'phone' => $context['phone'] ?? null,
                'page_url' => $context['page_url'] ?? null,
                'page_title' => $context['page_title'] ?? null,
                'referrer' => $context['referrer'] ?? null,
                'user_agent' => Str::limit((string) ($context['user_agent'] ?? ''), 480, ''),
                'ip_address' => $context['ip'] ?? null,
                'started_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addDays(self::SESSION_DAYS),
            ]);

            // Given straight away, so the agent sees where the visitor is
            // before they say anything — which is most of what makes a chat
            // reply useful rather than an interrogation.
            if (($context['page_url'] ?? null) !== null) {
                $this->inbox->reply($conversation, sprintf(
                    'Visitor is on %s%s',
                    $context['page_title'] ?? $context['page_url'],
                    ($context['referrer'] ?? null) !== null ? ' (from '.$context['referrer'].')' : '',
                ), ['internal' => true, 'automated' => true, 'sent_by' => null]);
            }

            if ($this->hasIdentity($context)) {
                $this->attachCustomer($session, $conversation, $context);
            }

            return ['session' => $session->refresh(), 'token' => $token, 'conversation' => $conversation->refresh()];
        });
    }

    /**
     * Something the visitor typed.
     */
    public function send(string $token, string $body, array $options = []): Message
    {
        $session = $this->sessionFor($token);
        $conversation = $session->conversation;

        if ($conversation === null) {
            throw new RuntimeException('That chat session has no conversation.');
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        return $this->inbox->receive(
            $conversation->channel,
            $conversation->contact_handle,
            $body,
            [
                // Ours to mint, since we are the platform here. Still unique
                // per conversation, so a double-tap on send is one message.
                'external_id' => $options['client_id'] ?? 'web_'.Str::lower((string) Str::ulid()),
                'kind' => $options['kind'] ?? 'text',
            ],
        );
    }

    /**
     * The thread as the visitor should see it.
     *
     * Internal notes are excluded — that is the whole point of them, and a
     * widget that leaked one would be worse than not having them.
     *
     * @return array{messages: list<array<string, mixed>>, is_online: bool}
     */
    public function transcript(string $token, ?string $since = null): array
    {
        $session = $this->sessionFor($token);
        $session->forceFill(['last_seen_at' => now()])->save();

        $messages = Message::query()
            ->where('conversation_id', $session->conversation_id)
            ->visible()
            ->when($since !== null, fn ($q) => $q->where('occurred_at', '>', $since))
            ->orderBy('occurred_at')
            ->limit(200)
            ->get();

        return [
            'messages' => $messages->map(fn (Message $m) => [
                'id' => $m->public_id,
                // "them" and "us" from the visitor's point of view, not ours.
                'from' => $m->direction === Message::IN ? 'you' : 'agent',
                'body' => $m->body,
                'at' => $m->occurred_at?->toIso8601String(),
            ])->all(),
            'is_online' => $session->widget?->isWithinOfficeHours() ?? true,
        ];
    }

    /**
     * The visitor tells us who they are.
     *
     * The moment they stop being anonymous — and the moment the conversation
     * joins the rest of what is known about them.
     */
    public function identify(string $token, array $details): WebchatSession
    {
        $session = $this->sessionFor($token);

        $session->forceFill(array_filter([
            'name' => $details['name'] ?? null,
            'email' => $details['email'] ?? null,
            'phone' => $details['phone'] ?? null,
        ]))->save();

        if ($this->hasIdentity($details)) {
            $this->attachCustomer($session, $session->conversation, $details);
        }

        return $session->refresh();
    }

    /**
     * Create the widget and the channel it feeds, together.
     */
    public function createWidget(array $attributes = []): WebchatWidget
    {
        return DB::transaction(function () use ($attributes) {
            $channel = InboxChannel::create([
                'kind' => InboxChannel::WEBCHAT,
                'name' => $attributes['name'] ?? 'Live chat',
                // No window: this is our platform, and there is nobody to ask.
                'reply_window_hours' => null,
            ]);

            return WebchatWidget::create([
                'inbox_channel_id' => $channel->id,
                'name' => $attributes['name'] ?? 'Live chat',
                'widget_key' => 'wk_'.Str::lower(Str::random(32)),
                ...$attributes,
            ]);
        });
    }

    /** The snippet a subscriber pastes into their site. */
    public function embedSnippet(WebchatWidget $widget): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return <<<HTML
        <script>
          (function(w,d,k){w.PrismChat=w.PrismChat||{key:k};
            var s=d.createElement('script');s.async=1;
            s.src='{$url}/chat/widget.js?k='+k;
            d.head.appendChild(s);})(window,document,'{$widget->widget_key}');
        </script>
        HTML;
    }

    private function widgetFor(string $widgetKey, ?string $origin): WebchatWidget
    {
        $widget = WebchatWidget::query()
            ->withoutGlobalScopes()
            ->with('channel')
            ->where('widget_key', $widgetKey)
            ->first();

        if ($widget === null || ! $widget->is_enabled) {
            // The same message either way. Distinguishing "no such widget" from
            // "that one is switched off" tells somebody probing which keys are
            // real.
            throw new RuntimeException('That chat widget is not available.');
        }

        if (! $widget->allowsOrigin($origin)) {
            throw new RuntimeException('This chat widget is not enabled for this website.');
        }

        return $widget;
    }

    /**
     * The session a token belongs to.
     *
     * Looked up by hash, and the token is never compared in the application —
     * a hash lookup is a single indexed read and leaks nothing through timing.
     */
    private function sessionFor(string $token): WebchatSession
    {
        $session = WebchatSession::query()
            ->withoutGlobalScopes()
            ->with('conversation.channel', 'widget')
            ->where('token_hash', WebchatSession::hash($token))
            ->first();

        if ($session === null || ! $session->isLive()) {
            throw new RuntimeException('That chat session has expired. Start a new one.');
        }

        $this->enterTenancy($session->widget);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function hasIdentity(array $details): bool
    {
        return ($details['email'] ?? null) !== null || ($details['phone'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function attachCustomer(WebchatSession $session, ?Conversation $conversation, array $details): void
    {
        if ($conversation === null) {
            return;
        }

        $traits = array_filter([
            'email' => $details['email'] ?? null,
            'phone' => $details['phone'] ?? null,
        ]);

        if ($traits === []) {
            return;
        }

        $customer = $this->customers->resolve($traits, array_filter([
            'name' => $details['name'] ?? null,
        ]));

        $this->inbox->link($conversation, $customer);
    }

    /**
     * Establish tenancy from the widget, since no middleware has.
     *
     * Every model below this point is scoped, and a request from the open
     * internet arrives with nothing set. Without this the conversation lookup
     * finds nothing and a visitor's message lands nowhere.
     */
    private function enterTenancy(?WebchatWidget $widget): void
    {
        if ($widget === null) {
            return;
        }

        $this->tenant->setAccount(Account::withoutGlobalScopes()->find($widget->account_id));
        $this->tenant->setBusiness(Business::withoutGlobalScopes()->find($widget->business_id));
    }
}
