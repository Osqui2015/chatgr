/**
 * Ambient declarations for the Vite environment and the browser globals the
 * application relies on.
 */

import type Pusher from 'pusher-js'

interface ImportMetaEnv {
  /** The public key of the Reverb application. */
  readonly VITE_REVERB_APP_KEY: string
  /** The host that serves the WebSocket connection. */
  readonly VITE_REVERB_HOST: string
  /** The port that serves the WebSocket connection. */
  readonly VITE_REVERB_PORT: string
  /** Either `http` or `https`. */
  readonly VITE_REVERB_SCHEME: string
  /** The base URL of the public application, used for absolute links. */
  readonly VITE_APP_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

export {}

declare global {
  interface Window {
    /** The Pusher client used by Laravel Echo. */
    Pusher: typeof Pusher
  }
}

export {}
