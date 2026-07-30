import {
    ChatConfig,
    ChatConversation,
    ChatConversationDetails,
    ChatMessage,
    ChatMessageRole,
    ChatMessageStatus,
    ChatTool,
    ChatToolCall,
    ChatToolCallStatus,
} from '@/api/server/chat/types';

// The assistant endpoints return plain (non-fractal) objects, so these transformers
// operate on the raw snake_case payload rather than a { object, attributes } wrapper.
export interface RawChatToolCall {
    id: string;
    name: string;
    summary: string;
    status: ChatToolCallStatus;
    ok: boolean | null;
    destructive?: boolean;
    arguments?: Record<string, unknown>;
    result?: { ok: boolean; [key: string]: unknown } | null;
}

export interface RawChatMessage {
    uuid: string;
    role: ChatMessageRole;
    content: string | null;
    reasoning?: string | null;
    status: ChatMessageStatus;
    tool_calls?: RawChatToolCall[] | null;
    created_at: string;
}

export interface RawChatConversation {
    uuid: string;
    title: string | null;
    created_at: string;
    last_message_at: string | null;
    messages?: RawChatMessage[] | null;
}

export interface RawChatTool {
    name: string;
    group: string;
    description: string;
    destructive?: boolean;
}

export interface RawChatConfig {
    enabled: boolean;
    model: string | null;
    requires_confirmation: boolean;
    tools?: RawChatTool[] | null;
}

export const rawDataToChatToolCall = (data: RawChatToolCall): ChatToolCall => ({
    id: data.id,
    name: data.name,
    summary: data.summary,
    status: data.status,
    ok: data.ok ?? null,
    destructive: data.destructive ?? false,
    arguments: data.arguments ?? {},
    result: data.result ?? null,
});

export const rawDataToChatMessage = (data: RawChatMessage): ChatMessage => ({
    uuid: data.uuid,
    role: data.role,
    content: data.content,
    reasoning: data.reasoning ?? null,
    status: data.status,
    toolCalls: (data.tool_calls || []).map(rawDataToChatToolCall),
    createdAt: new Date(data.created_at),
});

export const rawDataToChatConversation = (data: RawChatConversation): ChatConversation => ({
    uuid: data.uuid,
    title: data.title,
    createdAt: new Date(data.created_at),
    lastMessageAt: data.last_message_at ? new Date(data.last_message_at) : null,
});

export const rawDataToChatConversationDetails = (data: RawChatConversation): ChatConversationDetails => ({
    ...rawDataToChatConversation(data),
    messages: (data.messages || []).map(rawDataToChatMessage),
});

export const rawDataToChatTool = (data: RawChatTool): ChatTool => ({
    name: data.name,
    group: data.group,
    description: data.description,
    destructive: data.destructive ?? false,
});

export const rawDataToChatConfig = (data: RawChatConfig): ChatConfig => ({
    enabled: data.enabled,
    model: data.model ?? null,
    requiresConfirmation: data.requires_confirmation,
    tools: (data.tools || []).map(rawDataToChatTool),
});
