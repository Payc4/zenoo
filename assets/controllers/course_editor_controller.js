import { Controller } from "@hotwired/stimulus"

/*
 * Course studio slide editor (templates/admin/course_studio/editor.html.twig).
 *
 * Left: slide list (click to open, drag to reorder). Middle: the boxes of the
 * selected slide, built from the layout definitions in App\Course\LessonLayouts.
 * Right: a phone preview rendered by the real lesson player (POSTed to an iframe).
 * Nothing is stored until Save (Ctrl/Cmd+S also saves).
 */
export default class extends Controller {
    static targets = ["layouts", "lessonData", "transitions", "title", "subtitle", "duration", "published",
        "status", "saveBtn", "list", "form", "frame", "previewForm", "previewPayload",
        "picker", "pickerGrid", "pickerTitle"]
    static values = { saveUrl: String, previewUrl: String, uploadUrl: String, voiceUrl: String, csrf: String }

    connect() {
        this.layouts = JSON.parse(this.layoutsTarget.textContent)
        this.transitions = JSON.parse(this.transitionsTarget.textContent)
        this.lesson = JSON.parse(this.lessonDataTarget.textContent)
        this.lesson.slides = Array.isArray(this.lesson.slides) ? this.lesson.slides : []
        this.current = 0

        this.titleTarget.value = this.lesson.title || ''
        this.subtitleTarget.value = this.lesson.subtitle || ''
        this.durationTarget.value = this.lesson.durationMinutes || ''
        this.publishedTarget.checked = !!this.lesson.isPublished

        this.onInput = this.onInput.bind(this)
        this.formTarget.addEventListener('input', this.onInput)
        this.formTarget.addEventListener('change', this.onInput)
        this.onKey = e => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); this.save() }
            if (e.key === 'Escape' && !this.pickerTarget.hidden) this.closePicker()
        }
        this.onLeave = e => { if (this.dirty) { e.preventDefault(); e.returnValue = '' } }
        window.addEventListener('keydown', this.onKey)
        window.addEventListener('beforeunload', this.onLeave)

        this.renderList()
        this.renderForm()
        this.refreshPreview(true)
        this.setStatus('saved')
    }

    disconnect() {
        window.removeEventListener('keydown', this.onKey)
        window.removeEventListener('beforeunload', this.onLeave)
    }

    // ───────────────────────── helpers ─────────────────────────

    get slides() { return this.lesson.slides }
    get slide() { return this.slides[this.current] }

    esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
    }

    newId() {
        return 's_' + Array.from(crypto.getRandomValues(new Uint8Array(4)), b => b.toString(16).padStart(2, '0')).join('')
    }

    clone(v) { return JSON.parse(JSON.stringify(v)) }

    emptyFor(def) {
        if (def.default !== undefined) return this.clone(def.default)
        switch (def.type) {
            case 'list': case 'group': return []
            case 'choices': return [{ text: '', correct: true }, { text: '', correct: false }]
            case 'checkbox': return false
            case 'number': return 0
            default: return ''
        }
    }

    /** Fills any missing keys of a fields object from its definitions. */
    normalize(defs, values) {
        const out = { ...(values || {}) }
        for (const def of defs) {
            if (out[def.key] === undefined || out[def.key] === null) out[def.key] = this.emptyFor(def)
            if (def.type === 'group') {
                out[def.key] = (Array.isArray(out[def.key]) ? out[def.key] : []).map(item => this.normalize(def.fields, item))
            }
        }
        return out
    }

    newSlide(layout) {
        return { id: this.newId(), layout, transition: 'slide', fields: this.normalize(this.layouts[layout].fields, {}) }
    }

    getPath(path) {
        return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), this.slide.fields)
    }

    setPath(path, value) {
        const keys = path.split('.')
        let o = this.slide.fields
        keys.slice(0, -1).forEach(k => { o = o[k] })
        o[keys.at(-1)] = value
    }

    findDef(path) {
        // e.g. "questions.0.choices" → the choices definition inside the questions group
        let defs = this.layouts[this.slide.layout].fields
        let def = null
        for (const part of path.split('.')) {
            if (/^\d+$/.test(part)) continue
            def = defs.find(d => d.key === part)
            if (!def) return null
            defs = def.fields || []
        }
        return def
    }

    // ───────────────────────── status & saving ─────────────────────────

    setStatus(state, text) {
        const el = this.statusTarget
        el.dataset.state = state
        el.textContent = text || { saved: 'Saved', dirty: 'Unsaved changes', saving: 'Saving…', error: 'Not saved' }[state]
    }

    changed() {
        this.dirty = true
        this.setStatus('dirty')
        this.refreshPreview()
    }

    metaChanged() {
        this.lesson.title = this.titleTarget.value
        this.lesson.subtitle = this.subtitleTarget.value
        this.lesson.durationMinutes = parseInt(this.durationTarget.value, 10) || null
        this.lesson.isPublished = this.publishedTarget.checked
        this.dirty = true
        this.setStatus('dirty')
    }

    async save() {
        this.metaChanged()
        this.setStatus('saving')
        this.saveBtnTarget.disabled = true
        try {
            const res = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify(this.lesson),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) throw new Error(data.error || 'Save failed')
            this.dirty = false
            this.setStatus('saved', `Saved ${data.savedAt}`)
        } catch (e) {
            this.setStatus('error', e.message || 'Not saved')
        } finally {
            this.saveBtnTarget.disabled = false
        }
    }

    // ───────────────────────── preview ─────────────────────────

    refreshPreview(now = false) {
        clearTimeout(this.previewTimer)
        this.previewTimer = setTimeout(() => {
            this.previewPayloadTarget.value = JSON.stringify({ slides: this.slides, index: this.current })
            this.previewFormTarget.action = this.previewUrlValue
            this.previewFormTarget.submit()
        }, now ? 0 : 450)
    }

    // ───────────────────────── slide list ─────────────────────────

    renderList() {
        this.listTarget.innerHTML = this.slides.map((s, i) => {
            const l = this.layouts[s.layout] || { label: s.layout, icon: '•' }
            const hint = (s.fields?.headline || s.fields?.badge || '').toString().slice(0, 40)
            return `<li class="cs-thumb${i === this.current ? ' is-active' : ''}" draggable="true" data-index="${i}"
                        data-action="click->course-editor#select dragstart->course-editor#dragStart dragover->course-editor#dragOver drop->course-editor#drop dragend->course-editor#dragEnd">
                      <span class="cs-thumb__n">${i + 1}</span>
                      <span class="cs-thumb__icon">${l.icon}</span>
                      <span class="cs-thumb__text"><strong>${this.esc(l.label)}</strong><small>${this.esc(hint) || '&nbsp;'}</small></span>
                    </li>`
        }).join('')
    }

    select(event) {
        const i = parseInt(event.currentTarget.dataset.index, 10)
        if (i === this.current) return
        this.current = i
        this.renderList()
        this.renderForm()
        this.refreshPreview(true)
    }

    dragStart(event) {
        this.dragFrom = parseInt(event.currentTarget.dataset.index, 10)
        event.dataTransfer.effectAllowed = 'move'
        event.currentTarget.classList.add('is-dragging')
    }

    dragOver(event) {
        event.preventDefault()
        this.listTarget.querySelectorAll('.cs-thumb').forEach(el => el.classList.remove('is-over'))
        event.currentTarget.classList.add('is-over')
    }

    drop(event) {
        event.preventDefault()
        const to = parseInt(event.currentTarget.dataset.index, 10)
        this.moveSlideTo(this.dragFrom, to)
    }

    dragEnd() {
        this.listTarget.querySelectorAll('.cs-thumb').forEach(el => el.classList.remove('is-over', 'is-dragging'))
    }

    moveSlideTo(from, to) {
        if (from === undefined || from === to || to < 0 || to >= this.slides.length) return
        const [s] = this.slides.splice(from, 1)
        this.slides.splice(to, 0, s)
        this.current = to
        this.renderList()
        this.renderForm()
        this.changed()
    }

    moveSlide(event) {
        const dir = parseInt(event.currentTarget.dataset.dir, 10)
        this.moveSlideTo(this.current, this.current + dir)
    }

    duplicateSlide() {
        const copy = this.clone(this.slide)
        copy.id = this.newId()
        this.slides.splice(this.current + 1, 0, copy)
        this.current++
        this.renderList()
        this.renderForm()
        this.changed()
    }

    deleteSlide() {
        if (!confirm('Delete this slide?')) return
        this.slides.splice(this.current, 1)
        this.current = Math.max(0, Math.min(this.current, this.slides.length - 1))
        this.renderList()
        this.renderForm()
        this.changed()
    }

    // ───────────────────────── layout picker ─────────────────────────

    openPicker(event) {
        this.pickerMode = event?.currentTarget?.dataset.mode || 'add'
        this.pickerTitleTarget.textContent = this.pickerMode === 'change' ? 'Change this slide\'s layout' : 'Choose a layout for the new slide'
        this.pickerGridTarget.innerHTML = Object.entries(this.layouts).map(([key, l]) => `
            <button type="button" class="cs-layout" data-layout="${key}" data-action="course-editor#pickLayout">
              <span class="cs-layout__icon">${l.icon}</span>
              <strong>${this.esc(l.label)}</strong>
              <small>${this.esc(l.description)}</small>
            </button>`).join('')
        this.pickerTarget.hidden = false
    }

    closePicker() { this.pickerTarget.hidden = true }

    pickLayout(event) {
        const layout = event.currentTarget.dataset.layout
        this.closePicker()
        if (this.pickerMode === 'change' && this.slide) {
            if (layout === this.slide.layout) return
            const fresh = this.newSlide(layout)
            const newDefs = this.layouts[layout].fields
            for (const def of newDefs) {
                const old = this.layouts[this.slide.layout].fields.find(d => d.key === def.key && d.type === def.type)
                if (old && this.slide.fields[def.key] !== undefined) fresh.fields[def.key] = this.slide.fields[def.key]
            }
            fresh.id = this.slide.id
            fresh.transition = this.slide.transition
            fresh.fields = this.normalize(newDefs, fresh.fields)
            this.slides[this.current] = fresh
        } else {
            const at = this.slides.length ? this.current + 1 : 0
            this.slides.splice(at, 0, this.newSlide(layout))
            this.current = at
        }
        this.renderList()
        this.renderForm()
        this.changed()
    }

    // ───────────────────────── slide form ─────────────────────────

    renderForm() {
        const s = this.slide
        if (!s) {
            this.formTarget.innerHTML = `
              <div class="cs-empty-state">
                <p class="cs-h2">This lesson has no slides yet</p>
                <p class="cs-muted">Add a slide and pick a layout, like adding a slide in PowerPoint.</p>
                <button type="button" class="cs-btn" data-action="course-editor#openPicker">+ Add the first slide</button>
              </div>`
            return
        }
        const l = this.layouts[s.layout]
        s.fields = this.normalize(l.fields, s.fields)
        const transitionOptions = Object.entries(this.transitions)
            .map(([k, v]) => `<option value="${k}"${s.transition === k ? ' selected' : ''}>${v}</option>`).join('')

        this.formTarget.innerHTML = `
          <div class="cs-form__head">
            <div>
              <p class="cs-label">Slide ${this.current + 1} of ${this.slides.length}</p>
              <h2 class="cs-h2">${l.icon} ${this.esc(l.label)} <button type="button" class="cs-linkbtn" data-mode="change" data-action="course-editor#openPicker">change layout</button></h2>
              <p class="cs-muted cs-small">${this.esc(l.description)}</p>
            </div>
            <div class="cs-form__tools">
              <label class="cs-small">Transition
                <select class="cs-input cs-select" data-kind="transition">${transitionOptions}</select>
              </label>
              <button type="button" class="cs-icon" title="Move up" data-dir="-1" data-action="course-editor#moveSlide" ${this.current === 0 ? 'disabled' : ''}>↑</button>
              <button type="button" class="cs-icon" title="Move down" data-dir="1" data-action="course-editor#moveSlide" ${this.current === this.slides.length - 1 ? 'disabled' : ''}>↓</button>
              <button type="button" class="cs-icon" title="Duplicate slide" data-action="course-editor#duplicateSlide">⧉</button>
              <button type="button" class="cs-icon cs-icon--danger" title="Delete slide" data-action="course-editor#deleteSlide">✕</button>
            </div>
          </div>
          <div class="cs-fields">${l.fields.map(def => this.fieldHtml(def, s.fields[def.key], def.key)).join('')}</div>`
    }

    fieldHtml(def, value, path) {
        const help = def.help ? `<small class="cs-help">${this.esc(def.help)}</small>` : ''
        const ph = def.placeholder ? ` placeholder="${this.esc(def.placeholder)}"` : ''
        const label = `<span class="cs-field__label">${this.esc(def.label)}</span>`
        switch (def.type) {
            case 'text':
                return `<label class="cs-field">${label}<input class="cs-input" data-path="${path}" value="${this.esc(value)}"${ph}>${help}</label>`
            case 'textarea':
                return `<label class="cs-field">${label}<textarea class="cs-input" rows="${def.rows || 3}" data-path="${path}"${ph}>${this.esc(value)}</textarea>${help}</label>`
            case 'list':
                return `<label class="cs-field">${label}<textarea class="cs-input" rows="4" data-path="${path}" data-kind="list"${ph}>${this.esc((value || []).join('\n'))}</textarea>${help}</label>`
            case 'number':
                return `<label class="cs-field cs-field--inline">${label}<input class="cs-input cs-input--num" type="number" min="0" max="20" data-path="${path}" data-kind="number" value="${this.esc(value)}">${help}</label>`
            case 'checkbox':
                return `<label class="cs-check cs-field">${'<input type="checkbox" data-path="' + path + '" data-kind="checkbox"' + (value ? ' checked' : '') + '>'} ${this.esc(def.label)}</label>`
            case 'audio':
                return `<div class="cs-field">${label}
                    ${value ? `<audio controls src="${this.esc(value)}" class="cs-audio"></audio>
                       <div class="cs-row"><span class="cs-small cs-muted">${this.esc(value.split('/').pop())}</span>
                       <button type="button" class="cs-linkbtn cs-linkbtn--danger" data-path="${path}" data-action="course-editor#removeAudio">Remove</button></div>` : ''}
                    <div class="cs-audio-actions"><label class="cs-upload"><input type="file" accept="audio/*,.mp3,.m4a,.wav,.ogg" data-path="${path}" data-kind="audio" hidden>
                      <span class="cs-btn cs-btn--ghost cs-btn--sm">${value ? 'Replace audio' : 'Upload audio'}</span></label>
                    ${this.voiceUrlValue ? `<button type="button" class="cs-btn cs-btn--ghost cs-btn--sm" data-path="${path}" data-action="course-editor#generateVoice">🎙️ ${value ? 'Regenerate with ElevenLabs' : 'Generate with ElevenLabs'}</button>` : ''}</div>
                    ${help}</div>`
            case 'choices': {
                const items = (value || []).map((c, i) => `
                    <div class="cs-choice${c.correct ? ' is-correct' : ''}">
                      <label class="cs-choice__radio" title="Mark as the correct answer"><input type="radio" name="c-${path}" data-path="${path}" data-index="${i}" data-kind="correct"${c.correct ? ' checked' : ''}><span>${c.correct ? 'Correct' : ''}</span></label>
                      <input class="cs-input" data-path="${path}.${i}.text" value="${this.esc(c.text)}" placeholder="Answer ${i + 1}">
                      <button type="button" class="cs-icon" title="Remove answer" data-path="${path}" data-index="${i}" data-action="course-editor#removeChoice">✕</button>
                    </div>`).join('')
                return `<div class="cs-field">${label}${help}<div class="cs-choices">${items}</div>
                    ${(value || []).length < 6 ? `<button type="button" class="cs-linkbtn" data-path="${path}" data-action="course-editor#addChoice">+ Add answer</button>` : ''}</div>`
            }
            case 'group': {
                const items = (value || []).map((item, i) => `
                    <div class="cs-group">
                      <div class="cs-group__head">
                        <strong>${this.esc(def.itemLabel)} ${i + 1}</strong>
                        <span>
                          <button type="button" class="cs-icon" title="Move up" data-path="${path}" data-index="${i}" data-dir="-1" data-action="course-editor#moveItem" ${i === 0 ? 'disabled' : ''}>↑</button>
                          <button type="button" class="cs-icon" title="Move down" data-path="${path}" data-index="${i}" data-dir="1" data-action="course-editor#moveItem" ${i === value.length - 1 ? 'disabled' : ''}>↓</button>
                          <button type="button" class="cs-icon cs-icon--danger" title="Remove" data-path="${path}" data-index="${i}" data-action="course-editor#removeItem" ${value.length <= (def.min || 0) ? 'disabled' : ''}>✕</button>
                        </span>
                      </div>
                      ${def.fields.map(sub => this.fieldHtml(sub, item[sub.key], `${path}.${i}.${sub.key}`)).join('')}
                    </div>`).join('')
                return `<div class="cs-field"><span class="cs-field__label">${this.esc(def.label)}</span><div class="cs-groups">${items}</div>
                    ${(value || []).length < (def.max || 10) ? `<button type="button" class="cs-btn cs-btn--ghost cs-btn--sm" data-path="${path}" data-action="course-editor#addItem">+ Add ${this.esc(def.itemLabel.toLowerCase())}</button>` : ''}</div>`
            }
            default:
                return ''
        }
    }

    onInput(event) {
        const el = event.target
        if (el.dataset.kind === 'transition') {
            if (event.type === 'change') { this.slide.transition = el.value; this.changed() }
            return
        }
        if (el.dataset.kind === 'audio') {
            if (event.type === 'change') this.uploadAudio(el)
            return
        }
        const path = el.dataset.path
        if (!path) return
        // Avoid double handling: text inputs fire "input", checkboxes/radios fire "change".
        if ((el.type === 'checkbox' || el.type === 'radio') && event.type !== 'change') return
        if (el.type !== 'checkbox' && el.type !== 'radio' && event.type === 'change') return

        switch (el.dataset.kind) {
            case 'list':
                this.setPath(path, el.value.split('\n').map(x => x.trim()).filter(Boolean))
                break
            case 'number':
                this.setPath(path, Math.max(0, Math.min(20, parseInt(el.value, 10) || 0)))
                break
            case 'checkbox':
                this.setPath(path, el.checked)
                break
            case 'correct': {
                const list = this.getPath(path)
                list.forEach((c, i) => { c.correct = i === parseInt(el.dataset.index, 10) })
                this.renderForm()
                break
            }
            default:
                this.setPath(path, el.value)
        }
        if (path === 'headline' || path === 'badge') this.renderList()
        this.changed()
    }

    // ───────────────────────── repeatable items ─────────────────────────

    addChoice(event) {
        const list = this.getPath(event.currentTarget.dataset.path)
        list.push({ text: '', correct: list.length === 0 })
        this.renderForm()
        this.changed()
    }

    removeChoice(event) {
        const list = this.getPath(event.currentTarget.dataset.path)
        const [removed] = list.splice(parseInt(event.currentTarget.dataset.index, 10), 1)
        if (removed?.correct && list.length) list[0].correct = true
        this.renderForm()
        this.changed()
    }

    addItem(event) {
        const path = event.currentTarget.dataset.path
        const def = this.findDef(path)
        const list = this.getPath(path)
        const blank = def.key === 'questions'
            ? { choices: [{ text: '', correct: true }, { text: '', correct: false }, { text: '', correct: false }, { text: '', correct: false }] }
            : {}
        list.push(this.normalize(def.fields, blank))
        this.renderForm()
        this.changed()
    }

    removeItem(event) {
        if (!confirm('Remove this item?')) return
        this.getPath(event.currentTarget.dataset.path).splice(parseInt(event.currentTarget.dataset.index, 10), 1)
        this.renderForm()
        this.changed()
    }

    moveItem(event) {
        const list = this.getPath(event.currentTarget.dataset.path)
        const i = parseInt(event.currentTarget.dataset.index, 10)
        const j = i + parseInt(event.currentTarget.dataset.dir, 10)
        if (j < 0 || j >= list.length) return
        ;[list[i], list[j]] = [list[j], list[i]]
        this.renderForm()
        this.changed()
    }

    // ───────────────────────── audio ─────────────────────────

    async uploadAudio(input) {
        const file = input.files?.[0]
        if (!file) return
        if (file.size > 10 * 1024 * 1024) {
            alert('That file is over 10 MB. Please export a smaller MP3 (for example 96–128 kbps).')
            return
        }
        const path = input.dataset.path
        const button = input.nextElementSibling
        if (button) button.textContent = 'Uploading…'
        const form = new FormData()
        form.append('audio', file)
        try {
            const res = await fetch(this.uploadUrlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfValue }, body: form })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) throw new Error(data.error || 'Upload failed')
            this.setPath(path, data.url)
            if (this.slide.fields && 'ai_voice' in this.slide.fields) this.slide.fields.ai_voice = false
            this.renderForm()
            this.changed()
        } catch (e) {
            alert(e.message || 'Upload failed')
            this.renderForm()
        }
    }

    // Speech for this screen's script (fields.script) with ElevenLabs. Like an upload, it's kept once the lesson is saved.
    async generateVoice(event) {
        const button = event.currentTarget
        const path = button.dataset.path
        const fields = this.slide.fields || {}
        if (!(fields.script || '').trim()) { alert('Write the script (Transcript) first: that is what gets read out.'); return }
        if (fields.audio && !confirm('Replace the current audio with a new ElevenLabs voice?')) return
        button.disabled = true
        button.textContent = 'Making the voice… (up to a minute)'
        try {
            const res = await fetch(this.voiceUrlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfValue, 'Content-Type': 'application/json' },
                body: JSON.stringify({ script: fields.script }),
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) throw new Error(data.error || 'ElevenLabs didn\'t answer. Try again.')
            this.setPath(path, data.url)
            fields.ai_voice = true
            this.renderForm()
            this.changed()
        } catch (e) {
            alert(e.message)
            this.renderForm()
        }
    }

    removeAudio(event) {
        this.setPath(event.currentTarget.dataset.path, '')
        this.renderForm()
        this.changed()
    }
}
