export type ChatMessageRole = 'user' | 'assistant';

export type ChatMessageStatus = 'complete' | 'awaiting_confirmation' | 'failed' | 'denied';

export type ChatToolCallStatus = 'pending' | 'executed' | 'failed' | 'denied';

export interface ChatToolCall {
    id: string;
    name: string;
    summary: string;
    status: ChatToolCallStatus;
    ok: boolean | null;
    destructive: boolean;
}

export interface ChatMessage {
    uuid: string;
    role: ChatMessageRole;
    content: string | null;
    // Chain-of-thought from reasoning models. Hidden behind a disclosure — it is
    // context for the curious, not part of the answer.
    reasoning: string | null;
    status: ChatMessageStatus;
    toolCalls: ChatToolCall[];
    createdAt: Date;
}

export interface ChatConversation {
    uuid: string;
    title: string | null;
    createdAt: Date;
    lastMessageAt: Date | null;
}

export interface ChatConversationDetails extends ChatConversation {
    messages: ChatMessage[];
}

export interface ChatTool {
    name: string;
    group: string;
    description: string;
    destructive: boolean;
}

export interface ChatConfig {
    enabled: boolean;
    model: string | null;
    requiresConfirmation: boolean;
    tools: ChatTool[];
}
