/*
 * Admin course studio (templates/admin/course_studio/*).
 */
import './styles/course-studio.css';
import { Application } from '@hotwired/stimulus';
import CourseEditor from './controllers/course_editor_controller.js';
import VoiceBatch from './controllers/voice_batch_controller.js';

const app = Application.start();
app.register('course-editor', CourseEditor);
app.register('voice-batch', VoiceBatch);
