import { startPolling, type PollDeps } from '../pollTurn';

/**
 * Deterministic reproduction of the completion race: while one tick is awaiting
 * its GET, the interval fires another tick; both resolve to `complete`. Without
 * a one-shot latch, applyToolCalls runs once per completing tick.
 */
function makeDeps(over: Partial<PollDeps> = {}): {
  deps: PollDeps;
  applyToolCalls: jest.Mock;
  fireInterval: () => void;
} {
  let intervalCb: (() => void) | null = null;
  const applyToolCalls = jest.fn();
  const deps: PollDeps = {
    apiFetch: jest.fn().mockResolvedValue({
      status: 'complete',
      tool_calls: [{ tool: 'insert_block', input: {} }],
    }),
    applyToolCalls,
    onUpdate: jest.fn(),
    onTerminal: jest.fn().mockResolvedValue(undefined),
    onError: jest.fn(),
    isAborted: () => false,
    intervalMs: 300,
    schedule: (fn: () => void) => {
      intervalCb = fn;
      return 1;
    },
    cancel: () => {
      intervalCb = null;
    },
    ...over,
  };
  return { deps, applyToolCalls, fireInterval: () => intervalCb && intervalCb() };
}

describe('startPolling', () => {
  it('applies tool calls exactly once when overlapping ticks both see complete', async () => {
    const { deps, applyToolCalls, fireInterval } = makeDeps();

    startPolling(7, deps); // immediate tick begins (apiFetch pending)
    fireInterval(); // interval fires a second, overlapping tick
    fireInterval(); // and a third

    // Let every pending apiFetch microtask settle.
    await new Promise((r) => setImmediate(r));
    await new Promise((r) => setImmediate(r));

    expect(applyToolCalls).toHaveBeenCalledTimes(1);
  });

  it('does not apply tool calls for an errored turn, but still finalizes', async () => {
    const { deps, applyToolCalls } = makeDeps({
      apiFetch: jest.fn().mockResolvedValue({ status: 'error', tool_calls: [], error: { code: 'x' } }),
    });

    startPolling(7, deps);
    await new Promise((r) => setImmediate(r));

    expect(applyToolCalls).not.toHaveBeenCalled();
    expect(deps.onTerminal).toHaveBeenCalledTimes(1);
  });
});
