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
    arguments: Record<string, unknown>;
    result?: { ok: boolean; [key: string]: unknown } | null;
}

export type AgentProgressStatus = 'running' | 'complete' | 'failed';

export interface AgentProgress {
    // The uuid of the assistant message the agent's work belongs to.
    uuid: string;
    key: string;
    name: string;
    status: AgentProgressStatus;
    summary?: string | null;
}

export interface ChatMessage {
    uuid: string;
    // Set on the local echo of a message that has been typed but not yet
    // acknowledged by the server. Never present on anything the API returns.
    pending?: boolean;
    role: ChatMessageRole;
    content: string | null;
    // Chain-of-thought from reasoning models. Hidden behind a disclosure — it is
    // context for the curious, not part of the answer.
    reasoning: string | null;
    status: ChatMessageStatus;
    toolCalls: ChatToolCall[];
    // One entry per agent that worked on this message, updated as the `agent`
    // stream events arrive.
    // ponytail: agent runs are live-turn-only — they are never persisted in the
    // message list, so a reload drops them. If runs ever become part of history
    // this stays correct, only the source of the field changes.
    agentRuns?: AgentProgress[];
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
    orchestration: boolean;
}
