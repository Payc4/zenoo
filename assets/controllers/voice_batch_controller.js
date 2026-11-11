import { Controller } from '@hotwired/stimulus'

/*
 * Course studio: "Generate voices with ElevenLabs" for every Listen screen of a course that has no
 * audio yet. One screen per request (CourseStudioController::nextVoice), so it can be stopped any time
 * and nothing times out; each voice is saved on its lesson as soon as it's made.
 */
export default class extends Controller {
    static targets = ['status', 'button']
    static values = { url: String, csrf: String, remaining: Number }

    async start() {
        if (this.running) { this.stopped = true; return }
        if (!confirm(`Generate ${this.remainingValue} voice${this.remainingValue === 1 ? '' : 's'} with ElevenLabs? This uses your ElevenLabs credits.`)) return
        this.running = true
        this.stopped = false
        this.buttonTarget.textContent = 'Stop'
        let done = 0
        try {
            while (!this.stopped) {
                this.statusTarget.textContent = `Making voice ${done + 1} of ${done + this.remainingValue}…`
                const res = await fetch(this.urlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfValue } })
                const data = await res.json().catch(() => ({}))
                if (!res.ok || !data.ok) throw new Error(data.error || 'Something went wrong.')
                if (data.url) done++
                this.remainingValue = data.remaining
                if (data.remaining === 0) break
            }
            this.statusTarget.textContent = this.remainingValue === 0
                ? `Done: ${done} voice${done === 1 ? '' : 's'} made. Open a lesson to listen.`
                : `Stopped after ${done}. ${this.remainingValue} left.`
        } catch (e) {
            this.statusTarget.textContent = `${done} made, then: ${e.message}`
        }
        this.running = false
        this.buttonTarget.textContent = this.remainingValue === 0 ? 'Done' : 'Continue'
        this.buttonTarget.disabled = this.remainingValue === 0
    }
}
