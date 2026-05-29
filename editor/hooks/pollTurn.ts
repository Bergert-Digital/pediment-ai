/**
 * Polls a chat turn until it leaves the `streaming` state, then finalizes
 * exactly once: applies tool calls on success and runs the terminal callback.
 *
 * Extracted from useChatTurn so the completion concurrency is unit-testable.
 */

export type PollDeps = {
  apiFetch: (args: { path: string; method?: string }) => Promise<any>;
  applyToolCalls: (calls: any[]) => void;
  onUpdate: (t: any) => void;
  onTerminal?: (t: any) => Promise<void> | void;
  onError: (msg: string) => void;
  isAborted: () => boolean;
  intervalMs: number;
  schedule: (fn: () => void, ms: number) => number;
  cancel: (handle: number) => void;
};

export function startPolling(turnId: number, d: PollDeps): void {
  let timer: number | null = null;
  // One-shot latch: the turn is finalized exactly once. `inFlight` prevents a
  // new tick from starting while one is awaiting its GET, so overlapping polls
  // around completion can't each apply the same tool calls (duplicate page).
  let finished = false;
  let inFlight = false;

  const stopTimer = () => {
    if (timer !== null) {
      d.cancel(timer);
      timer = null;
    }
  };

  const tick = async () => {
    if (finished || inFlight) return;
    inFlight = true;
    try {
      const t = await d.apiFetch({ path: `/pediment-ai/v1/chat/turns/${turnId}`, method: 'GET' });
      if (finished) return;
      if (d.isAborted()) {
        finished = true;
        stopTimer();
        return;
      }
      d.onUpdate(t);
      if (t.status !== 'streaming') {
        // Latch synchronously before any await — single-threaded JS guarantees
        // only the first completing tick gets past here.
        finished = true;
        stopTimer();
        if (t.status === 'complete' && Array.isArray(t.tool_calls)) {
          d.applyToolCalls(t.tool_calls);
        }
        if (d.onTerminal) await d.onTerminal(t);
      }
    } catch (e: any) {
      finished = true;
      stopTimer();
      d.onError(e?.message ?? 'Polling failed');
    } finally {
      inFlight = false;
    }
  };

  void tick();
  timer = d.schedule(tick, d.intervalMs);
}
