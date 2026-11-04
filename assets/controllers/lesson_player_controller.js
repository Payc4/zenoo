import { Controller } from "@hotwired/stimulus"

/*
 * Lesson player (templates/course/_player.html.twig, styles: assets/styles/course-player.css).
 * Shows one slide at a time, checks quiz answers, saves written answers and the
 * "done it with my dog" tick, and marks the lesson complete on the achievement slide.
 * In editor preview (preview=true) nothing is sent to the server.
 */
export default class extends Controller {
    static targets = ["slide", "bar", "back", "achieveMeta"]
    static values = {
        start: Number,
        preview: Boolean,
        progressUrl: String,
        csrf: String,
        exitUrl: String,
        ui: Object,
    }

    connect() {
        this.index = Math.min(this.startValue || 0, Math.max(0, this.slideTargets.length - 1))
        this.answers = {}
        this.restoreSaved()
        this.show(this.index, 0, true)
    }

    /** Player words in the course language (App\\Course\\LessonLayouts::ui). */
    t(key) { return (this.uiValue && this.uiValue[key]) || key }

    // ───────────────────────── navigation ─────────────────────────

    get current() { return this.slideTargets[this.index] }

    show(i, dir = 1, instant = false) {
        const from = this.current
        const to = this.slideTargets[i]
        if (!to) return
        if (from && from !== to) {
            from.hidden = true
            from.classList.remove('is-in', 'from-left', 'from-right', 'is-fade')
        }
        this.index = i
        to.hidden = false
        const t = to.dataset.transition || 'slide'
        if (!instant && t !== 'none') {
            to.classList.remove('is-in')
            to.classList.add(t === 'fade' ? 'is-fade' : (dir < 0 ? 'from-left' : 'from-right'))
            void to.offsetWidth
            to.classList.add('is-in')
        }
        window.scrollTo(0, 0)
        if (this.hasBarTarget) {
            const n = this.slideTargets.length
            this.barTarget.style.width = `${n ? Math.round(((i + 1) / n) * 100) : 0}%`
        }
        if (this.hasBackTarget) this.backTarget.hidden = i === 0
        this.refreshRequirements()

        if (!instant) this.save({ slide: i })
        if (to.dataset.layout === 'achievement' && !this.previewValue) this.complete()
    }

    next(event) {
        const btn = event?.currentTarget
        if (btn && btn.dataset.requires && !this.requirementsMet(this.current, btn.dataset.requires)) {
            this.nudge(btn, btn.dataset.requires === 'tasks' ? this.t('needTask') : this.t('needAnswer'))
            return
        }
        if (this.index < this.slideTargets.length - 1) {
            this.show(this.index + 1, 1)
        } else {
            this.finish()
        }
    }

    prev() {
        if (this.index > 0) this.show(this.index - 1, -1)
    }

    noop(event) { event.preventDefault() }

    requirementsMet(slide, kind) {
        if (kind === 'questions') {
            return [...slide.querySelectorAll('.lp-q')].every(q => q.classList.contains('is-answered') || !q.querySelector('.lp-choice'))
        }
        if (kind === 'tasks') {
            return [...slide.querySelectorAll('.lp-task')].every(t => t.classList.contains('is-done'))
        }
        return true
    }

    refreshRequirements() {
        const btn = this.current?.querySelector('.lp-foot .lp-btn[data-requires]')
        if (btn) btn.classList.toggle('is-waiting', !this.requirementsMet(this.current, btn.dataset.requires))
    }

    nudge(el, message) {
        el.classList.remove('is-shake'); void el.offsetWidth; el.classList.add('is-shake')
        this.toast(message)
    }

    toast(message) {
        let t = this.element.querySelector('.lp-toast')
        if (!t) {
            t = document.createElement('div')
            t.className = 'lp-toast'
            t.setAttribute('role', 'status')
            this.element.appendChild(t)
        }
        t.textContent = message
        t.classList.add('is-on')
        clearTimeout(this.toastTimer)
        this.toastTimer = setTimeout(() => t.classList.remove('is-on'), 2600)
    }

    // ───────────────────────── questions ─────────────────────────

    choose(event) {
        const btn = event.currentTarget
        const q = btn.closest('.lp-q')
        if (!q || q.classList.contains('is-answered')) return
        this.markChoice(q, parseInt(btn.dataset.index, 10))

        const slide = btn.closest('.lp-slide')
        const qi = parseInt(q.dataset.qIndex || '0', 10)
        const a = this.answerFor(slide)
        a.quiz = a.quiz || {}
        a.quiz[qi] = { choice: parseInt(btn.dataset.index, 10), correct: btn.dataset.correct === '1' }
        this.refreshRequirements()
        this.save({ answers: { [slide.dataset.slideId]: a } })
    }

    markChoice(q, index) {
        const choices = [...q.querySelectorAll('.lp-choice')]
        const picked = choices[index]
        if (!picked) return
        const right = picked.dataset.correct === '1'
        q.classList.add('is-answered')
        picked.classList.add('is-picked', right ? 'is-right' : 'is-wrong')
        choices.forEach(c => { if (c.dataset.correct === '1') c.classList.add('is-right') ; c.disabled = true })
        const fb = q.querySelector(right ? '.lp-fb--right' : '.lp-fb--wrong')
        if (fb) fb.hidden = false
    }

    // ───────────────────────── writing ─────────────────────────

    submitTask(event) {
        const task = event.currentTarget.closest('.lp-task')
        const input = task.querySelector('.lp-input')
        const text = (input.value || '').trim()
        if (text.length < 3) {
            input.focus()
            this.nudge(event.currentTarget, this.t('needWords'))
            return
        }
        task.classList.add('is-done')
        const model = task.querySelector('[data-model]')
        if (model) model.hidden = false

        const slide = task.closest('.lp-slide')
        const ti = parseInt(task.dataset.taskIndex || '0', 10)
        const a = this.answerFor(slide)
        a.text = a.text || {}
        a.text[ti] = text
        this.refreshRequirements()
        this.save({ answers: { [slide.dataset.slideId]: a } })
    }

    // ───────────────────────── do it with your dog ─────────────────────────

    toggleDone(event) {
        const slide = event.currentTarget.closest('.lp-slide')
        const a = this.answerFor(slide)
        a.done = event.currentTarget.checked
        this.save({ answers: { [slide.dataset.slideId]: a } })
    }

    // ───────────────────────── saving ─────────────────────────

    answerFor(slide) {
        const id = slide.dataset.slideId
        this.answers[id] = this.answers[id] || {}
        return this.answers[id]
    }

    async save(payload) {
        if (this.previewValue || !this.progressUrlValue) return null
        try {
            const res = await fetch(this.progressUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify(payload),
            })
            return res.ok ? await res.json() : null
        } catch (e) {
            return null
        }
    }

    async complete() {
        if (this.completed) return
        this.completed = true
        const data = await this.save({ slide: this.index, complete: true })
        if (data && this.hasAchieveMetaTarget) {
            this.achieveMetaTargets.forEach(el => {
                el.textContent = this.t('lessonOf').replace('%n%', data.number).replace('%total%', data.lessonCount)
                    + (data.streak ? ' · ' + this.t('streak').replace('%n%', data.streak) + ' 🔥' : '')
            })
        }
        this.nextUrl = data?.next
    }

    async finish() {
        if (this.previewValue) {
            this.toast(this.t('previewEnd'))
            return
        }
        await this.complete()
        window.location.href = this.exitUrlValue
    }

    // ───────────────────────── restore ─────────────────────────

    restoreSaved() {
        this.element.querySelectorAll('.lp-q[data-saved]').forEach(q => {
            this.markChoice(q, parseInt(q.dataset.saved, 10))
        })
        this.element.querySelectorAll('.lp-task[data-done]').forEach(t => t.classList.add('is-done'))
    }
}
