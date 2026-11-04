/*
 * Lesson player page (templates/course/lesson.html.twig) and the studio's phone preview.
 */
import './styles/course-player.css';
import { Application } from '@hotwired/stimulus';
import LessonPlayer from './controllers/lesson_player_controller.js';

Application.start().register('lesson-player', LessonPlayer);
