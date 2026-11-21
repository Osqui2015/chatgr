import { readFile } from 'node:fs/promises'
import { join } from 'node:path'

/**
 * The directory that holds the mail log of the local environment.
 *
 * The mailer writes to the application log, so the verification code can be
 * read from there while the end to end tests run.
 */
const LOG_PATH = join(process.cwd(), '..', 'backend', 'storage', 'logs', 'laravel.log')

/**
 * A message found in the mail log.
 */
interface LoggedMessage {
  /** The recipient of the message. */
  to: string
  /** The whole block of the message. */
  body: string
}

/**
 * Split the log into the messages it contains.
 *
 * The log driver writes the raw MIME message of every mail, so each block
 * starts at the `From:` header and ends where the next one begins.
 */
function parseMessages(content: string): LoggedMessage[] {
  const messages: LoggedMessage[] = []

  for (const block of content.split(/(?=From: Chat <)/)) {
    const headers = block.split(/\r?\n\r?\n/, 1)[0] ?? ''
    const to = headers.match(/^To:\s*(.+)$/m)?.[1]?.trim()

    if (!to) {
      continue
    }

    messages.push({ to, body: block })
  }

  return messages
}

/**
 * The current size of the mail log.
 *
 * The value is used as a cursor, so the codes of the previous attempts are
 * discarded and only the newest message is read.
 */
export async function mailLogCursor(): Promise<number> {
  try {
    const content = await readFile(LOG_PATH, 'utf8')

    return content.length
  } catch {
    return 0
  }
}

/**
 * Read the verification code that was sent to an address.
 *
 * The plain text part of the message carries the code in a line of its own,
 * and the HTML part carries it in a paragraph, so both are inspected.
 *
 * @param since Only the messages written after this cursor are inspected.
 */
export async function readOtpCode(email: string, since = 0): Promise<string | null> {
  let content: string

  try {
    content = await readFile(LOG_PATH, 'utf8')
  } catch {
    return null
  }

  // Only inspect content appended after the cursor
  const newContent =
    since > 0 && since <= content.length
      ? content.slice(since)
      : since > content.length
        ? ''
        : content

  const messages = parseMessages(newContent)
    .filter((message) => message.to === email)
    .reverse()

  for (const message of messages) {
    const paragraph = message.body.match(/<p[^>]*>\s*(\d{6})\s*<\/p>/)

    if (paragraph?.[1]) {
      return paragraph[1]
    }

    const line = message.body.match(/^\s*(\d{6})\s*$/m)

    if (line?.[1]) {
      return line[1]
    }
  }

  return null
}

/**
 * Wait until a verification code for the address is available.
 *
 * The mail is delivered by a queued job, so the code may not be in the log
 * yet when the request that triggers it returns.
 */
export async function waitForOtpCode(
  email: string,
  since = 0,
  attempts = 30,
  delayMs = 400,
): Promise<string | null> {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    const code = await readOtpCode(email, since)

    if (code) {
      return code
    }

    await new Promise((resolve) => setTimeout(resolve, delayMs))
  }

  return null
}
