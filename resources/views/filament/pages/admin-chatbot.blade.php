<x-filament-panels::page>
    @php($labels = [
        'newConversation' => __('admin/chatbot.ui.new_conversation'),
        'placeholder' => __('admin/chatbot.ui.placeholder'),
        'send' => __('admin/chatbot.ui.send'),
        'thinking' => __('admin/chatbot.ui.thinking'),
        'approve' => __('admin/chatbot.ui.approve'),
        'deny' => __('admin/chatbot.ui.deny'),
        'waiting' => __('admin/chatbot.ui.waiting'),
        'thinkingToggle' => __('admin/chatbot.ui.thinking_toggle'),
        'disabled' => __('admin/chatbot.ui.disabled'),
        'empty' => __('admin/chatbot.ui.empty'),
        'error' => __('admin/chatbot.ui.error'),
        'conversations' => __('admin/chatbot.ui.conversations'),
        'delete' => __('admin/chatbot.ui.delete'),
        'failed' => __('admin/chatbot.ui.failed'),
        'executed' => __('admin/chatbot.ui.executed'),
        'untitled' => 'Untitled',
    ])
    <div
        x-data="adminChatbot(@js($labels))"
        x-init="init()"
        class="grid h-[calc(100vh-10rem)] grid-cols-1 gap-4 md:grid-cols-[280px_1fr]"
    >
        {{-- Conversation sidebar --}}
        <div class="flex flex-col gap-2 rounded-xl bg-white p-4 dark:bg-gray-900">
            <button
                type="button"
                x-on:click="newConversation()"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500"
            >
                <x-tabler-plus class="h-4 w-4" />
                <span x-text="labels.newConversation"></span>
            </button>

            <div class="mt-2 flex-1 space-y-1 overflow-y-auto">
                <template x-for="conversation in conversations" :key="conversation.uuid">
                    <div
                        x-on:click="selectConversation(conversation.uuid)"
                        class="group flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm"
                        :class="conversation.uuid === activeUuid ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                    >
                        <span class="truncate" x-text="conversation.title || labels.untitled"></span>
                        <button
                            type="button"
                            x-on:click.stop="deleteConversation(conversation.uuid)"
                            class="opacity-0 group-hover:opacity-100"
                            :title="labels.delete"
                        >
                            <x-tabler-trash class="h-4 w-4 text-gray-400 hover:text-danger-500" />
                        </button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Chat area --}}
        <div class="flex flex-col overflow-hidden rounded-xl bg-white dark:bg-gray-900">
            <div x-show="!config.enabled" class="m-4 rounded-lg bg-warning-50 p-4 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                <span x-text="labels.disabled"></span>
            </div>

            <template x-if="config.enabled">
                <div class="flex h-full flex-col">
                    {{-- Messages --}}
                    <div x-ref="messages" class="flex-1 space-y-4 overflow-y-auto p-4">
                        <template x-if="messages.length === 0">
                            <div class="flex h-full items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                                <span x-text="labels.empty"></span>
                            </div>
                        </template>

                        <template x-for="message in messages" :key="message.uuid">
                            <div class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                                <div
                                    class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                    :class="message.role === 'user'
                                        ? 'bg-primary-600 text-white'
                                        : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100'"
                                >
                                    {{-- Reasoning --}}
                                    <template x-if="message.reasoning">
                                        <div class="mb-2">
                                            <button
                                                type="button"
                                                x-on:click="message.showReasoning = !message.showReasoning"
                                                class="text-xs text-gray-500 underline"
                                            >
                                                <span x-text="labels.thinkingToggle"></span>
                                            </button>
                                            <div x-show="message.showReasoning" class="mt-1 whitespace-pre-wrap text-xs italic text-gray-500">
                                                <span x-text="message.reasoning"></span>
                                            </div>
                                        </div>
                                    </template>

                                    <div x-show="message.content" class="whitespace-pre-wrap" x-text="message.content"></div>

                                    {{-- Tool calls --}}
                                    <template x-if="message.tool_calls && message.tool_calls.length > 0">
                                        <div class="mt-2 space-y-2">
                                            <template x-for="call in message.tool_calls" :key="call.id">
                                                <div class="rounded-md border border-gray-200 p-2 text-xs dark:border-gray-700">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <code class="font-mono" x-text="call.name"></code>
                                                        <span
                                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                                                            :class="statusClass(call.status)"
                                                            x-text="statusLabel(call.status)"
                                                        ></span>
                                                    </div>
                                                    <div x-show="call.summary" class="mt-1 text-gray-600 dark:text-gray-300" x-text="call.summary"></div>
                                                    <div x-show="call.result && call.result.error" class="mt-1 text-danger-600 dark:text-danger-400" x-text="call.result.error"></div>

                                                    {{-- Approval buttons --}}
                                                    <div x-show="call.status === 'pending'" class="mt-2 flex gap-2">
                                                        <button
                                                            type="button"
                                                            x-on:click="decide(message, call.id, true)"
                                                            class="rounded bg-success-600 px-2 py-1 text-xs font-medium text-white hover:bg-success-500"
                                                        >
                                                            <span x-text="labels.approve"></span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            x-on:click="decide(message, call.id, false)"
                                                            class="rounded bg-danger-600 px-2 py-1 text-xs font-medium text-white hover:bg-danger-500"
                                                        >
                                                            <span x-text="labels.deny"></span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="message.status === 'awaiting_confirmation'">
                                        <div class="mt-1 text-xs font-medium text-warning-600 dark:text-warning-400">
                                            <span x-text="labels.waiting"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Composer --}}
                    <div class="border-t border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex items-end gap-2">
                            <textarea
                                x-model="draft"
                                x-on:keydown.enter.prevent="send()"
                                rows="2"
                                class="flex-1 resize-none rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                                :placeholder="labels.placeholder"
                                :disabled="busy"
                            ></textarea>
                            <button
                                type="button"
                                x-on:click="send()"
                                :disabled="busy || !draft.trim()"
                                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                            >
                                <span x-show="!busy" x-text="labels.send"></span>
                                <span x-show="busy" x-text="labels.thinking"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        function adminChatbot(labels) {
            return {
                labels,
                config: { enabled: false, requires_confirmation: true, tools: [] },
                conversations: [],
                messages: [],
                activeUuid: null,
                draft: '',
                busy: false,

                init() {
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
                    this.messages = (data.data.messages || []).map((m) => ({ ...m, showReasoning: false }));
                    this.scrollToBottom();
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
                    const normalized = { ...message, showReasoning: false };
                    if (index === -1) {
                        this.messages.push(normalized);
                    } else {
                        this.messages[index] = { ...this.messages[index], ...normalized };
                    }
                },

                upsertCall(messageUuid, call) {
                    const message = this.messages.find((m) => m.uuid === messageUuid);
                    if (!message || !message.tool_calls) return;
                    const index = message.tool_calls.findIndex((c) => c.id === call.id);
                    if (index === -1) {
                        message.tool_calls.push(call);
                    } else {
                        message.tool_calls[index] = { ...message.tool_calls[index], ...call };
                    }
                },

                async send() {
                    const content = this.draft.trim();
                    if (!content || this.busy || !this.activeUuid) return;
                    this.draft = '';
                    this.busy = true;

                    this.messages.push({
                        uuid: 'local-' + Math.random().toString(36).slice(2),
                        role: 'user',
                        content,
                        status: 'complete',
                        tool_calls: [],
                        showReasoning: false,
                    });
                    this.scrollToBottom();

                    try {
                        await this.stream(`/admin/chat/conversations/${this.activeUuid}/messages/stream`, { content });
                    } catch (error) {
                        this.alert(error.message || this.labels.error);
                    } finally {
                        this.busy = false;
                        this.scrollToBottom();
                    }
                },

                async decide(message, callId, approved) {
                    if (this.busy) return;
                    this.busy = true;

                    try {
                        await this.stream(`/admin/chat/conversations/${this.activeUuid}/confirm/stream`, {
                            message_uuid: message.uuid,
                            decisions: [{ id: callId, approved }],
                        });
                    } catch (error) {
                        this.alert(error.message || this.labels.error);
                    } finally {
                        this.busy = false;
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
                                    this.scrollToBottom();
                                }
                                break;
                            case 'reasoning':
                                if (typeof payload.uuid === 'string' && typeof payload.content === 'string') {
                                    const message = this.messages.find((m) => m.uuid === payload.uuid);
                                    if (message) message.reasoning = (message.reasoning || '') + payload.content;
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

                statusClass(status) {
                    return {
                        pending: 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300',
                        executed: 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300',
                        failed: 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300',
                        denied: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                    }[status] || 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
                },

                statusLabel(status) {
                    return {
                        pending: this.labels.waiting,
                        executed: this.labels.executed,
                        failed: this.labels.failed,
                        denied: this.labels.deny,
                    }[status] || status;
                },

                alert(message) {
                    window.Filament?.notification?.(message) ?? window.alert(message);
                },

                scrollToBottom() {
                    this.$nextTick(() => {
                        const el = this.$refs.messages;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
