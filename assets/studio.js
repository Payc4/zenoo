/*
 * Admin course studio (templates/admin/course_studio/*).
 */
import './styles/course-studio.css';
import { Application } from '@hotwired/stimulus';
import CourseEditor from './controllers/course_editor_controller.js';

Application.start().register('course-editor', CourseEditor);
