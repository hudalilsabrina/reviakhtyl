<x-filament-panels::page>
    @php($labels = [
        'newConversation' => __('admin/chatbot.ui.new_conversation'),
        'placeholder' => __('admin/chatbot.ui.placeholder'),
        'send' => __('admin/chatbot.ui.send'),
        'thinking' => __('admin/chatbot.ui.thinking'),
        'approve' => __('admin/chatbot.ui.approve'),
        'deny' => __('admin/chatbot.ui.deny'),
        'waiting' => __('admin/chatbot.ui.waiting'),
        'destructive' => __('admin/chatbot.ui.destructive'),
        'destructiveInvalid' => __('admin/chatbot.ui.destructive_invalid'),
        'destructivePlaceholder' => __('admin/chatbot.ui.destructive_placeholder'),
        'thinkingToggle' => __('admin/chatbot.ui.thinking_toggle'),
        'thinkingHide' => __('admin/chatbot.ui.thinking_hide'),
        'disabled' => __('admin/chatbot.ui.disabled'),
        'empty' => __('admin/chatbot.ui.empty'),
        'emptyTitle' => __('admin/chatbot.ui.empty_title'),
        'noConversations' => __('admin/chatbot.ui.no_conversations'),
        'error' => __('admin/chatbot.ui.error'),
        'conversations' => __('admin/chatbot.ui.conversations'),
        'delete' => __('admin/chatbot.ui.delete'),
        'failed' => __('admin/chatbot.ui.failed'),
        'executed' => __('admin/chatbot.ui.executed'),
        'untitled' => 'Untitled',
        'assistant' => __('admin/chatbot.ui.assistant'),
        'you' => __('admin/chatbot.ui.you'),
        'copy' => __('admin/chatbot.ui.copy'),
        'copied' => __('admin/chatbot.ui.copied'),
        'details' => __('admin/chatbot.ui.details'),
        'hideDetails' => __('admin/chatbot.ui.hide_details'),
        'params' => __('admin/chatbot.ui.params'),
        'result' => __('admin/chatbot.ui.result'),
        'resultSuccess' => __('admin/chatbot.ui.result_success'),
        'resultFailure' => __('admin/chatbot.ui.result_failure'),
        'resultEmpty' => __('admin/chatbot.ui.result_empty'),
        'suggestion1' => __('admin/chatbot.ui.suggestion_1'),
        'suggestion2' => __('admin/chatbot.ui.suggestion_2'),
        'suggestion3' => __('admin/chatbot.ui.suggestion_3'),
        'suggestion4' => __('admin/chatbot.ui.suggestion_4'),
        'closeSidebar' => __('admin/chatbot.ui.close_sidebar'),
        'openSidebar' => __('admin/chatbot.ui.open_sidebar'),
        'stop' => __('admin/chatbot.ui.stop'),
        'copiedHint' => __('admin/chatbot.ui.copied_hint'),
        'sendingHint' => __('admin/chatbot.ui.sending_hint'),
        'conversationWith' => __('admin/chatbot.ui.conversation_with'),
    ])
    <div
        x-data="adminChatbot(@js($labels))"
        x-init="init()"
        class="chat-layout"
    >
        {{-- Conversation sidebar --}}
        <x-filament::section
            class="chat-section h-full overflow-hidden"
            :heading="$labels['conversations']"
            icon="tabler-message-2"
            icon-color="primary"
            x-cloak
            x-show="sidebarOpen"
        >
            <x-slot name="afterHeader">
                <x-filament::button
                    color="primary"
                    size="sm"
                    icon="tabler-plus"
                    x-on:click="newConversation()"
                    x-bind:disabled="busy"
                >
                    <span x-text="labels.newConversation"></span>
                </x-filament::button>
            </x-slot>

            <div class="flex h-full flex-col gap-1 overflow-y-auto">
                <template x-if="conversations.length === 0">
                    <div class="my-auto py-10 text-center text-sm text-gray-400 dark:text-gray-500" x-text="labels.noConversations"></div>
                </template>

                <template x-for="conversation in conversations" :key="conversation.uuid">
                    <div
                        x-on:click="selectConversation(conversation.uuid)"
                        class="group relative flex cursor-pointer items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-sm transition"
                        :class="conversation.uuid === activeUuid
                            ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300'
                            : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                    >
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-medium" x-text="conversation.title || labels.untitled"></span>
                            <span class="text-[10px] uppercase tracking-wide opacity-60" x-text="relativeTime(conversation.last_message_at)"></span>
                        </div>
                        <button
                            type="button"
                            x-on:click.stop="deleteConversation(conversation.uuid)"
                            class="shrink-0 rounded p-1 opacity-0 transition group-hover:opacity-100 hover:bg-danger-50 hover:text-danger-500 dark:hover:bg-danger-500/10"
                            :title="labels.delete"
                        >
                            <x-tabler-trash class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </template>
            </div>
        </x-filament::section>

        {{-- Chat area --}}
        <x-filament::section
            class="chat-section h-full overflow-hidden"
            icon="tabler-robot"
            icon-color="primary"
        >
            <x-slot name="heading">
                <span x-text="labels.conversationWith"></span>
            </x-slot>

            <x-slot name="description">
                <template x-if="config.enabled">
                    <span class="flex flex-wrap items-center gap-x-1.5">
                        <span x-text="config.model"></span>
                        <template x-if="busy">
                            <span class="flex items-center gap-1">
                                <x-tabler-loader-2 class="h-3 w-3 animate-spin" />
                                <span x-text="labels.thinking"></span>
                            </span>
                        </template>
                    </span>
                </template>
            </x-slot>

            <x-slot name="afterHeader">
                <x-filament::icon-button
                    color="gray"
                    icon="tabler-menu-2"
                    x-on:click="sidebarOpen = true"
                    class="lg:hidden"
                />
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="tabler-plus"
                    x-on:click="newConversation()"
                    x-show="messages.length > 0 && activeUuid"
                >
                    <span x-text="labels.newConversation"></span>
                </x-filament::button>
            </x-slot>

            {{-- Disabled banner --}}
            <x-filament::callout
                color="warning"
                icon="tabler-alert-triangle"
                :description="$labels['disabled']"
                x-show="!config.enabled"
            />

            <template x-if="config.enabled">
                <div class="flex h-full min-h-0 flex-col">
                    {{-- Messages --}}
                    <div x-ref="messages" class="min-h-0 flex-1 space-y-6 overflow-y-auto">
                        {{-- Welcome --}}
                        <template x-if="messages.length === 0">
                            <div class="flex h-full items-center justify-center">
                                <x-filament::empty-state
                                    icon="tabler-robot"
                                    :heading="$labels['emptyTitle']"
                                    :description="$labels['empty']"
                                >
                                    <x-slot name="footer">
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            <template x-for="suggestion in suggestions" :key="suggestion">
                                                <button
                                                    type="button"
                                                    x-on:click="draft = suggestion; $nextTick(() => $refs.composer?.focus())"
                                                    class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-600 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 dark:hover:text-primary-300"
                                                    x-text="suggestion"
                                                ></button>
                                            </template>
                                        </div>
                                    </x-slot>
                                </x-filament::empty-state>
                            </div>
                        </template>

                        <template x-for="message in messages" :key="message.uuid">
                            <div class="group message-in flex items-start gap-3" :class="message.role === 'user' ? 'flex-row-reverse' : ''">
                                {{-- Avatar --}}
                                <template x-if="message.role !== 'user'">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                        <x-tabler-robot class="h-4 w-4" />
                                    </div>
                                </template>
                                <template x-if="message.role === 'user'">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600/10 text-primary-600 dark:text-primary-400">
                                        <x-tabler-user class="h-4 w-4" />
                                    </div>
                                </template>

                                <div class="flex min-w-0 max-w-full flex-col gap-1.5 sm:max-w-[80%]" :class="message.role === 'user' ? 'items-end' : 'items-start'">
                                    {{-- Role label --}}
                                    <span
                                        class="px-1 text-[11px] font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500"
                                        :class="message.role === 'user' ? 'text-right' : ''"
                                        x-text="message.role === 'user' ? labels.you : labels.assistant"
                                    ></span>
                                    {{-- Reasoning --}}
                                    <template x-if="message.reasoning">
                                        <div class="w-full">
                                            <button
                                                type="button"
                                                x-on:click="message.showReasoning = !message.showReasoning"
                                                class="inline-flex items-center gap-1.5 rounded-lg px-1 py-0.5 text-xs text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                            >
                                                <x-tabler-brain class="h-3 w-3" />
                                                <span x-text="message.showReasoning || streamingUuid === message.uuid ? labels.thinkingHide : labels.thinkingToggle"></span>
                                                <x-tabler-chevron-down class="h-3 w-3 transition-transform duration-150" x-bind:class="message.showReasoning ? 'rotate-180' : ''" />
                                            </button>
                                            <div
                                                x-show="message.showReasoning"
                                                class="mt-1 max-h-64 overflow-y-auto whitespace-pre-wrap break-words rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs leading-relaxed text-gray-500 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-400"
                                                x-text="message.reasoning"
                                            ></div>
                                        </div>
                                    </template>

                                    {{-- Tool calls --}}
                                    <template x-if="message.tool_calls && message.tool_calls.length > 0">
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="call in message.tool_calls" :key="call.id">
                                                <div class="flex flex-col">
                                                    <button
                                                        type="button"
                                                        x-on:click="call.open = !call.open"
                                                        class="inline-flex max-w-full items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium transition"
                                                        :class="chipClass(call.status)"
                                                        :title="call.name"
                                                    >
                                                        <template x-if="call.status === 'pending'"><x-tabler-hourglass class="h-3 w-3 shrink-0" /></template>
                                                        <template x-if="call.status === 'executed'"><x-tabler-check class="h-3 w-3 shrink-0" /></template>
                                                        <template x-if="call.status === 'failed'"><x-tabler-x class="h-3 w-3 shrink-0" /></template>
                                                        <template x-if="call.status === 'denied'"><x-tabler-ban class="h-3 w-3 shrink-0" /></template>
                                                        <span class="truncate" x-text="call.summary || call.name"></span>
                                                        <x-tabler-chevron-down class="h-2.5 w-2.5 shrink-0 transition-transform duration-150" x-bind:class="call.open ? 'rotate-180' : ''" />
                                                    </button>

                                                    {{-- Expandable details --}}
                                                    <div x-show="call.open" class="mt-1.5 ml-1 space-y-1.5">
                                                        <div>
                                                            <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-400" x-text="labels.params"></div>
                                                            <pre class="max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-2 text-[11px] text-gray-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300" x-text="prettyJson(call.arguments)"></pre>
                                                        </div>
                                                        <div>
                                                            <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-400" x-text="labels.result"></div>
                                                            <template x-if="call.result === null || call.result === undefined">
                                                                <div class="text-[11px] italic text-gray-400" x-text="labels.resultEmpty"></div>
                                                            </template>
                                                            <template x-if="call.result && call.result.ok === true">
                                                                <div class="rounded-lg border border-success-200 bg-success-50 p-2 dark:border-success-500/20 dark:bg-success-500/5">
                                                                    <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-success-600 dark:text-success-400" x-text="labels.resultSuccess"></div>
                                                                    <pre class="max-h-48 overflow-y-auto whitespace-pre-wrap break-words text-[11px] text-success-700 dark:text-success-300" x-text="prettyResult(call.result)"></pre>
                                                                </div>
                                                            </template>
                                                            <template x-if="call.result && call.result.ok === false">
                                                                <div class="rounded-lg border border-danger-200 bg-danger-50 p-2 dark:border-danger-500/20 dark:bg-danger-500/5">
                                                                    <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-danger-600 dark:text-danger-400" x-text="labels.resultFailure"></div>
                                                                    <pre class="max-h-48 overflow-y-auto whitespace-pre-wrap break-words text-[11px] text-danger-700 dark:text-danger-300" x-text="call.result.error || call.result.note"></pre>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Bubble --}}
                                    <div
                                        class="max-w-full break-words rounded-xl border px-3.5 py-2.5 text-sm leading-relaxed shadow-sm"
                                        :class="message.role === 'user'
                                            ? 'rounded-br-md border-primary-200 bg-primary-600 text-white dark:border-primary-500/30'
                                            : message.status === 'failed'
                                                ? 'rounded-bl-md border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-500/20 dark:bg-danger-500/10 dark:text-danger-200'
                                                : 'rounded-bl-md border-gray-200 bg-gray-50 text-gray-800 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-100'"
                                    >
                                        <div
                                            x-show="message.content"
                                            class="whitespace-pre-wrap [overflow-wrap:anywhere]"
                                            x-text="message.content"
                                        ></div>
                                        <div x-show="!message.content && message.status !== 'failed'" class="typing-dots text-xs italic opacity-60">
                                            <span></span><span></span><span></span>
                                        </div>

                                        {{-- Pending approval note --}}
                                        <template x-if="message.status === 'awaiting_confirmation'">
                                            <div class="mt-2 flex items-center gap-1.5 rounded-lg bg-warning-50 px-2 py-1.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                                                <x-tabler-hourglass class="h-3.5 w-3.5" />
                                                <span x-text="labels.waiting"></span>
                                            </div>
                                        </template>

                                        {{-- Failed note --}}
                                        <template x-if="message.status === 'failed'">
                                            <div class="mt-2 flex items-center gap-1.5 text-xs text-danger-600 dark:text-danger-400">
                                                <x-tabler-alert-triangle class="h-3.5 w-3.5" />
                                                <span x-text="labels.failed"></span>
                                            </div>
                                        </template>

                                        {{-- Streaming cursor --}}
                                        <span
                                            x-show="streamingUuid === message.uuid && message.content"
                                            class="ml-0.5 inline-block h-4 w-1.5 animate-pulse rounded-sm bg-current align-text-bottom"
                                        ></span>
                                    </div>

                                    {{-- Approval buttons --}}
                                    <div
                                        x-show="message.tool_calls && message.tool_calls.some(c => c.status === 'pending')"
                                        class="flex items-center gap-2"
                                        :class="message.role === 'user' ? 'flex-row-reverse' : ''"
                                    >
                                        {{-- Typed confirmation for destructive admin actions --}}
                                        <div
                                            x-show="pendingDestructiveVerb(message) !== null"
                                            class="flex flex-col gap-1.5"
                                            :class="message.role === 'user' ? 'items-end' : 'items-start'"
                                        >
                                            <span class="text-xs text-danger-600 dark:text-danger-400"
                                                x-text="labels.destructive.replace(':verb', pendingDestructiveVerb(message))"
                                            ></span>
                                            <input
                                                type="text"
                                                x-model="confirmInputs[message.uuid]"
                                                x-bind:placeholder="labels.destructivePlaceholder"
                                                class="rounded-lg border border-danger-300 bg-white px-2.5 py-1 text-xs text-danger-700 focus:border-danger-500 focus:outline-none dark:border-danger-700 dark:bg-gray-800 dark:text-danger-300"
                                            />
                                        </div>

                                        <x-filament::button
                                            color="success"
                                            size="sm"
                                            icon="tabler-check"
                                            x-on:click="decide(message, allPendingIds(message), true)"
                                            x-bind:disabled="busy || !confirmOk(message)"
                                        >
                                            <span x-text="labels.approve"></span>
                                        </x-filament::button>
                                        <x-filament::button
                                            color="danger"
                                            size="sm"
                                            icon="tabler-x"
                                            x-on:click="decide(message, allPendingIds(message), false)"
                                            x-bind:disabled="busy"
                                        >
                                            <span x-text="labels.deny"></span>
                                        </x-filament::button>
                                    </div>

                                    {{-- Meta row: time + copy --}}
                                    <div
                                        class="flex items-center gap-2 px-1 text-[10px] text-gray-400 dark:text-gray-500"
                                        :class="message.role === 'user' ? 'flex-row-reverse' : ''"
                                    >
                                        <span x-text="relativeTime(message.created_at)"></span>
                                        <button
                                            type="button"
                                            x-on:click="copyMessage(message)"
                                            class="inline-flex items-center gap-1 rounded opacity-0 transition hover:text-gray-600 group-hover:opacity-100 dark:hover:text-gray-300"
                                        >
                                            <template x-if="copiedUuid !== message.uuid">
                                                <x-tabler-copy class="h-3 w-3" />
                                            </template>
                                            <template x-if="copiedUuid === message.uuid">
                                                <x-tabler-check class="h-3 w-3 text-success-500" />
                                            </template>
                                            <span x-text="copiedUuid === message.uuid ? labels.copied : labels.copy"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Composer --}}
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        <div class="flex items-end gap-2">
                            <x-filament::input.wrapper class="flex-1">
                                <textarea
                                    x-model="draft"
                                    x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                                    x-on:input="autoGrow($event)"
                                    x-ref="composer"
                                    rows="1"
                                    placeholder="{{ $labels['placeholder'] }}"
                                    x-bind:disabled="busy"
                                    class="w-full resize-none bg-transparent px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none dark:text-gray-100 dark:placeholder-gray-500"
                                ></textarea>
                            </x-filament::input.wrapper>
                            <x-filament::button
                                color="primary"
                                x-on:click="send()"
                                x-bind:disabled="busy || !draft.trim()"
                            >
                                <template x-if="!busy">
                                    <x-tabler-send class="h-4 w-4" />
                                </template>
                                <template x-if="busy">
                                    <x-tabler-loader-2 class="h-4 w-4 animate-spin" />
                                </template>
                                <span x-text="busy ? labels.thinking : labels.send"></span>
                            </x-filament::button>
                        </div>
                        <p class="mt-1.5 px-1 text-[10px] text-gray-400 dark:text-gray-500" x-text="labels.sendingHint"></p>
                    </div>
                </div>
            </template>
        </x-filament::section>
    </div>

    <style>
        [x-cloak] { display: none !important; }

        .chat-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            height: calc(100vh - 9rem);
        }
        @media (min-width: 1024px) {
            .chat-layout { grid-template-columns: 300px 1fr; }
        }

        .chat-section { display: flex; flex-direction: column; }
        .chat-section > .fi-section-content-ctn { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        .chat-section > .fi-section-content-ctn > .fi-section-content { flex: 1 1 auto; min-height: 0; }

        @keyframes message-in {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: none; }
        }
        .message-in { animation: message-in 0.25s ease-out; }

        @keyframes typing-dot {
            0%, 60%, 100% { opacity: 0.25; transform: translateY(0); }
            30% { opacity: 1; transform: translateY(-2px); }
        }
        .typing-dots { display: inline-flex; align-items: center; }
        .typing-dots span {
            width: 5px; height: 5px; margin-right: 4px; border-radius: 9999px;
            background: currentColor; animation: typing-dot 1.2s infinite;
        }
        .typing-dots span:nth-child(2) { animation-delay: 0.15s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.3s; }
    </style>

    <script>
        function adminChatbot(labels) {
            return {
                labels,
                config: { enabled: false, requires_confirmation: true, tools: [], model: null },
                conversations: [],
                messages: [],
                activeUuid: null,
                draft: '',
                busy: false,
                streamingUuid: null,
                copiedUuid: null,
                sidebarOpen: false,
                suggestions: [labels.suggestion1, labels.suggestion2, labels.suggestion3, labels.suggestion4],

                get activeTitle() {
                    const conversation = this.conversations.find((c) => c.uuid === this.activeUuid);
                    return conversation?.title || labels.untitled;
                },

                init() {
                    this.sidebarOpen = window.innerWidth >= 1024;
                    this.loadConfig().then(() => {
                        if (this.config.enabled) this.loadConversations();
                    });
                },

                headers() {
                    return {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': this.csrf(),
                    };
                },

                csrf() {
                    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];
                    return cookie ? decodeURIComponent(cookie) : '';
                },

                async loadConfig() {
                    const response = await fetch('/admin/chat/config', { headers: this.headers() });
                    this.config = await response.json();
                },

                async loadConversations() {
                    const response = await fetch('/admin/chat/conversations', { headers: this.headers() });
                    const data = await response.json();
                    this.conversations = data.data || [];
                    if (this.conversations.length > 0) {
                        await this.selectConversation(this.conversations[0].uuid);
                    }
                },

                async newConversation() {
                    if (this.busy) return;
                    const response = await fetch('/admin/chat/conversations', {
                        method: 'POST',
                        headers: this.headers(),
                    });
                    const data = await response.json();
                    this.conversations.unshift(data.data);
                    await this.selectConversation(data.data.uuid);
                },

                async selectConversation(uuid) {
                    if (this.busy || uuid === this.activeUuid) return;
                    this.activeUuid = uuid;
                    this.messages = [];
                    const response = await fetch(`/admin/chat/conversations/${uuid}`, { headers: this.headers() });
                    const data = await response.json();
                    this.messages = (data.data.messages || []).map((m) => ({
                        ...m,
                        showReasoning: false,
                        tool_calls: (m.tool_calls || []).map((call) => ({ ...call, open: false })),
                    }));
                    this.sidebarOpen = window.innerWidth >= 1024;
                    this.scrollToBottom(true);
                },

                async deleteConversation(uuid) {
                    if (this.busy) return;
                    await fetch(`/admin/chat/conversations/${uuid}`, {
                        method: 'DELETE',
                        headers: this.headers(),
                    });
                    this.conversations = this.conversations.filter((c) => c.uuid !== uuid);
                    if (uuid === this.activeUuid) {
                        this.activeUuid = null;
                        this.messages = [];
                        if (this.conversations.length > 0) {
                            await this.selectConversation(this.conversations[0].uuid);
                        }
                    }
                },

                upsertMessage(message) {
                    const index = this.messages.findIndex((m) => m.uuid === message.uuid);
                    const normalized = {
                        ...message,
                        showReasoning: index === -1 ? false : this.messages[index].showReasoning,
                        tool_calls: (message.tool_calls || []).map((call) => ({
                            ...call,
                            open: index === -1 ? false : this.messages[index].tool_calls?.find((c) => c.id === call.id)?.open ?? false,
                        })),
                    };
                    if (index === -1) {
                        // Local echoes are dropped wholesale: the server returns
                        // the real message with its own uuid, so keeping both
                        // would show the text twice.
                        this.messages = [...this.messages.filter((m) => !m.pending), normalized];
                    } else {
                        this.messages[index] = { ...this.messages[index], ...normalized };
                    }
                    this.messages = [...this.messages].sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
                },

                upsertCall(messageUuid, call) {
                    const message = this.messages.find((m) => m.uuid === messageUuid);
                    if (!message) return;
                    if (!message.tool_calls) message.tool_calls = [];
                    const index = message.tool_calls.findIndex((c) => c.id === call.id);
                    if (index === -1) {
                        message.tool_calls.push({ ...call, open: false });
                    } else {
                        message.tool_calls[index] = { ...message.tool_calls[index], ...call };
                    }
                },

                async send() {
                    const content = this.draft.trim();
                    if (!content || this.busy || !this.activeUuid) return;
                    this.draft = '';
                    this.busy = true;
                    this.autoGrow(null);

                    this.messages.push({
                        uuid: 'local-' + Math.random().toString(36).slice(2),
                        role: 'user',
                        content,
                        status: 'complete',
                        tool_calls: [],
                        showReasoning: false,
                        pending: true,
                        created_at: new Date().toISOString(),
                    });
                    this.scrollToBottom();

                    try {
                        await this.stream(`/admin/chat/conversations/${this.activeUuid}/messages/stream`, { content });
                    } catch (error) {
                        this.alert(error.message || this.labels.error);
                    } finally {
                        this.busy = false;
                        this.streamingUuid = null;
                        this.scrollToBottom();
                        this.$nextTick(() => this.$refs.composer?.focus());
                    }
                },

                allPendingIds(message) {
                    return (message.tool_calls || [])
                        .filter((call) => call.status === 'pending')
                        .map((call) => call.id);
                },

                // Administrative tools that permanently change the panel. Their
                // Approve button additionally requires typing a confirmation verb.
                destructiveVerbs: {
                    'delete_server': 'delete server',
                    'delete_user': 'delete user',
                    'create_server': 'create server',
                    'create_user': 'create user',
                },

                confirmInputs: {},

                isDestructiveCall(call) {
                    return this.destructiveVerbs[call?.name] !== undefined;
                },

                pendingDestructiveVerb(message) {
                    const call = (message.tool_calls || []).find((c) => c.status === 'pending' && this.isDestructiveCall(c));
                    return call ? this.destructiveVerbs[call.name] : null;
                },

                confirmOk(message) {
                    const verb = this.pendingDestructiveVerb(message);
                    if (verb === null) return true;
                    return (this.confirmInputs[message.uuid] || '').trim().toLowerCase() === verb;
                },

                async decide(message, callIds, approved) {
                    if (this.busy || callIds.length === 0) return;
                    if (approved && !this.confirmOk(message)) {
                        this.alert(this.labels.destructiveInvalid);
                        return;
                    }
                    this.busy = true;

                    try {
                        await this.stream(`/admin/chat/conversations/${this.activeUuid}/confirm/stream`, {
                            message_uuid: message.uuid,
                            decisions: callIds.map((id) => ({ id, approved })),
                            confirmation: approved ? (this.confirmInputs[message.uuid] || '') : '',
                        });
                    } catch (error) {
                        this.alert(error.message || this.labels.error);
                    } finally {
                        this.busy = false;
                        this.streamingUuid = null;
                    }
                },

                async stream(path, body) {
                    const response = await fetch(path, {
                        method: 'POST',
                        headers: { ...this.headers(), 'Accept': 'text/event-stream' },
                        body: JSON.stringify(body),
                    });

                    if (!response.ok) {
                        let message = this.labels.error;
                        try {
                            const data = await response.json();
                            message = data.errors?.[0]?.detail || message;
                        } catch (e) {}
                        throw new Error(message);
                    }

                    if (!response.body) throw new Error(this.labels.error);

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let eventName = '';
                    let eventData = [];

                    const dispatch = (name, data) => {
                        let payload;
                        try {
                            payload = JSON.parse(data);
                        } catch (e) {
                            return;
                        }
                        if (payload === null || typeof payload !== 'object') return;

                        switch (name) {
                            case 'message':
                                if (payload.message) this.upsertMessage(payload.message);
                                break;
                            case 'delta':
                                if (typeof payload.uuid === 'string' && typeof payload.content === 'string') {
                                    const message = this.messages.find((m) => m.uuid === payload.uuid);
                                    if (message) message.content = (message.content || '') + payload.content;
                                    this.streamingUuid = payload.uuid;
                                    this.scrollToBottom();
                                }
                                break;
                            case 'reasoning':
                                if (typeof payload.uuid === 'string' && typeof payload.content === 'string') {
                                    const message = this.messages.find((m) => m.uuid === payload.uuid);
                                    if (message) {
                                        message.reasoning = (message.reasoning || '') + payload.content;
                                        message.showReasoning = true;
                                    }
                                }
                                break;
                            case 'tool':
                                if (typeof payload.uuid === 'string' && payload.call) {
                                    this.upsertCall(payload.uuid, payload.call);
                                    this.scrollToBottom();
                                }
                                break;
                            case 'done':
                                if (Array.isArray(payload.messages)) {
                                    payload.messages.forEach((m) => this.upsertMessage(m));
                                }
                                this.streamingUuid = null;
                                break;
                            case 'error':
                                if (typeof payload.message === 'string') this.alert(payload.message);
                                break;
                        }
                    };

                    for (;;) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });

                        let boundary = buffer.indexOf('\n\n');
                        while (boundary !== -1) {
                            const block = buffer.slice(0, boundary);
                            buffer = buffer.slice(boundary + 2);

                            let name = '';
                            const data = [];
                            block.split('\n').forEach((line) => {
                                if (line.length === 0 || line.startsWith(':')) return;
                                const separator = line.indexOf(':');
                                const field = separator === -1 ? line : line.slice(0, separator);
                                const raw = separator === -1 ? '' : line.slice(separator + 1);
                                const value = raw.startsWith(' ') ? raw.slice(1) : raw;
                                if (field === 'event') name = value;
                                else if (field === 'data') data.push(value);
                            });

                            if (name !== '' && data.length > 0) {
                                dispatch(name, data.join('\n'));
                            }

                            boundary = buffer.indexOf('\n\n');
                        }
                    }
                },

                chipClass(status) {
                    return {
                        pending: 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300',
                        executed: 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300',
                        failed: 'border-danger-200 bg-danger-50 text-danger-700 dark:border-danger-500/20 dark:bg-danger-500/10 dark:text-danger-300',
                        denied: 'border-gray-200 bg-gray-100 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400',
                    }[status] || 'border-gray-200 bg-gray-100 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
                },

                prettyJson(args) {
                    if (!args || Object.keys(args).length === 0) return '(none)';
                    try {
                        return JSON.stringify(args, null, 2);
                    } catch (e) {
                        return String(args);
                    }
                },

                prettyResult(result) {
                    if (!result) return '';
                    const { ok, ...rest } = result;
                    if (Object.keys(rest).length === 0) return '';
                    const lines = Object.entries(rest).map(([key, value]) => {
                        const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
                        return `${key}: ${text}`;
                    });
                    return lines.join('\n');
                },

                statusClass(status) {
                    return this.chipClass(status);
                },

                statusLabel(status) {
                    return {
                        pending: this.labels.waiting,
                        executed: this.labels.executed,
                        failed: this.labels.failed,
                        denied: this.labels.deny,
                    }[status] || status;
                },

                relativeTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    const diff = Date.now() - date.getTime();
                    const minutes = Math.floor(diff / 60000);
                    if (minutes < 1) return 'now';
                    if (minutes < 60) return `${minutes}m`;
                    const hours = Math.floor(minutes / 60);
                    if (hours < 24) return `${hours}h`;
                    const days = Math.floor(hours / 24);
                    if (days < 7) return `${days}d`;
                    return date.toLocaleDateString();
                },

                copyMessage(message) {
                    const text = message.content || '';
                    if (!text) return;
                    if (navigator.clipboard?.writeText) {
                        navigator.clipboard.writeText(text);
                    } else {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        ta.remove();
                    }
                    this.copiedUuid = message.uuid;
                    setTimeout(() => { if (this.copiedUuid === message.uuid) this.copiedUuid = null; }, 1500);
                },

                autoGrow(event) {
                    this.$nextTick(() => {
                        const el = this.$refs.composer;
                        if (!el) return;
                        el.style.height = 'auto';
                        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
                    });
                },

                alert(message) {
                    window.Filament?.notification?.(message) ?? window.alert(message);
                },

                scrollToBottom(force = false) {
                    this.$nextTick(() => {
                        const el = this.$refs.messages;
                        if (!el) return;
                        if (force) {
                            el.scrollTop = el.scrollHeight;
                            return;
                        }
                        // Stick to the bottom when already near it; otherwise the
                        // user reading an earlier message is not yanked down.
                        if (el.scrollHeight - el.scrollTop - el.clientHeight < 120) {
                            el.scrollTop = el.scrollHeight;
                        }
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
