import { beforeEach, vi } from 'vitest'

/**
 * jsdom does not implement the observers the components rely on, so they are
 * replaced by inert stubs that keep the test environment predictable.
 */
class ResizeObserverStub implements ResizeObserver {
  public constructor(private readonly callback: ResizeObserverCallback) {}

  public observe(): void {
    // The stub never reports a size change.
  }

  public unobserve(): void {
    // Nothing to release.
  }

  public disconnect(): void {
    // Nothing to release.
  }
}

globalThis.ResizeObserver = globalThis.ResizeObserver ?? ResizeObserverStub

beforeEach(() => {
  vi.restoreAllMocks()
})
