<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuizAttempt;
use App\Models\Notification;
use App\Models\ForumCategory;
use App\Models\ForumTopic;
use App\Models\ForumPost;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        echo "🚀 Création des données de démonstration...\n\n";

        // 1. Utilisateurs
        echo "👥 Création des utilisateurs...\n";

        $etudiant1 = User::firstOrCreate(['email' => 'marie.dupont@test.com'], [
            'klassci_id' => 100001,
            'name' => 'Marie Dupont',
            'password' => Hash::make('password'),
            'role' => 'étudiant',
        ]);

        $etudiant2 = User::firstOrCreate(['email' => 'jean.martin@test.com'], [
            'klassci_id' => 100002,
            'name' => 'Jean Martin',
            'password' => Hash::make('password'),
            'role' => 'étudiant',
        ]);

        $enseignant1 = User::firstOrCreate(['email' => 'prof.leclerc@test.com'], [
            'klassci_id' => 200001,
            'name' => 'Prof. Leclerc',
            'password' => Hash::make('password'),
            'role' => 'enseignant',
        ]);

        $enseignant2 = User::firstOrCreate(['email' => 'dr.bernard@test.com'], [
            'klassci_id' => 200002,
            'name' => 'Dr. Bernard',
            'password' => Hash::make('password'),
            'role' => 'enseignant',
        ]);

        echo "   ✓ 4 utilisateurs créés\n\n";

        // 2. Leçons
        echo "📚 Création des leçons...\n";

        $lessons = [
            [
                'title' => 'Introduction à la Programmation',
                'description' => 'Découvrez les bases de la programmation avec Python',
                'content' => '<h2>Bienvenue dans le monde de la programmation !</h2><p>Dans ce cours, vous allez apprendre les concepts fondamentaux de la programmation en utilisant Python. Nous verrons les variables, les boucles, les conditions et bien plus encore.</p><h3>Objectifs</h3><ul><li>Comprendre les variables</li><li>Maîtriser les structures de contrôle</li><li>Créer vos premiers programmes</li></ul>',
                'status' => 'published',
                'type' => 'cours',
                'enseignant_id' => $enseignant1->id,
            ],
            [
                'title' => 'Mathématiques Avancées',
                'description' => 'Algèbre linéaire et calcul différentiel',
                'content' => '<h2>Mathématiques pour l\'ingénierie</h2><p>Ce cours couvre les concepts avancés de mathématiques nécessaires pour les études d\'ingénierie.</p><h3>Programme</h3><ul><li>Matrices et vecteurs</li><li>Dérivées partielles</li><li>Intégrales multiples</li></ul>',
                'status' => 'published',
                'type' => 'cours',
                'enseignant_id' => $enseignant2->id,
            ],
            [
                'title' => 'Bases de Données SQL',
                'description' => 'Maîtrisez MySQL et les requêtes SQL',
                'content' => '<h2>Introduction aux bases de données</h2><p>Apprenez à créer, gérer et interroger des bases de données relationnelles.</p><h3>Contenu</h3><ul><li>Modèle relationnel</li><li>CREATE, SELECT, INSERT, UPDATE, DELETE</li><li>Jointures et sous-requêtes</li></ul>',
                'status' => 'published',
                'type' => 'cours',
                'enseignant_id' => $enseignant1->id,
            ],
            [
                'title' => 'Développement Web Frontend',
                'description' => 'HTML, CSS, JavaScript et Vue.js',
                'content' => '<h2>Créez des interfaces web modernes</h2><p>Maîtrisez les technologies frontend pour créer des applications web interactives.</p>',
                'status' => 'published',
                'type' => 'cours',
                'enseignant_id' => $enseignant1->id,
            ],
            [
                'title' => 'Machine Learning - Brouillon',
                'description' => 'Introduction au ML (en préparation)',
                'content' => '<p>Contenu en cours de rédaction...</p>',
                'status' => 'draft',
                'type' => 'cours',
                'enseignant_id' => $enseignant2->id,
            ],
        ];

        foreach ($lessons as $lessonData) {
            Lesson::firstOrCreate(
                ['title' => $lessonData['title']],
                $lessonData
            );
        }

        echo "   ✓ 5 leçons créées\n\n";

        // 3. Quiz
        echo "📝 Création des quiz...\n";

        $quiz1 = Quiz::firstOrCreate(['title' => 'Quiz Python Débutant'], [
            'created_by' => $enseignant1->id,
            'description' => 'Testez vos connaissances en Python',
            'duration_minutes' => 30,
            'passing_score' => 60,
            'max_attempts' => 3,
        ]);

        $quiz2 = Quiz::firstOrCreate(['title' => 'Évaluation Mathématiques'], [
            'created_by' => $enseignant2->id,
            'description' => 'Quiz sur l\'algèbre linéaire',
            'duration_minutes' => 45,
            'passing_score' => 70,
            'max_attempts' => 2,
        ]);

        // Questions pour Quiz 1
        QuizQuestion::firstOrCreate(['quiz_id' => $quiz1->id, 'question_text' => 'Quelle est la sortie de print(2 + 3) ?'], [
            'question_type' => 'multiple_choice',
            'options' => ['5', '23', 'Erreur', '2+3'],
            'correct_answer' => '5',
            'points' => 10,
        ]);

        QuizQuestion::firstOrCreate(['quiz_id' => $quiz1->id, 'question_text' => 'Python est un langage compilé'], [
            'question_type' => 'true_false',
            'options' => ['true', 'false'],
            'correct_answer' => 'false',
            'points' => 10,
        ]);

        // Questions pour Quiz 2
        QuizQuestion::firstOrCreate(['quiz_id' => $quiz2->id, 'question_text' => 'Quelle est la dérivée de x² ?'], [
            'question_type' => 'multiple_choice',
            'options' => ['2x', 'x', '2', 'x²'],
            'correct_answer' => '2x',
            'points' => 15,
        ]);

        echo "   ✓ 2 quiz avec questions créés\n\n";

        // 4. Notifications
        echo "🔔 Création des notifications...\n";

        $notifications = [
            [
                'user_id' => $etudiant1->id,
                'type' => 'lesson_published',
                'title' => 'Nouvelle leçon disponible',
                'message' => 'La leçon "Introduction à la Programmation" est maintenant disponible !',
                'read_at' => null,
            ],
            [
                'user_id' => $etudiant1->id,
                'type' => 'quiz_available',
                'title' => 'Nouveau quiz',
                'message' => 'Le quiz "Python Débutant" vous attend !',
                'read_at' => now()->subDay(),
            ],
            [
                'user_id' => $etudiant2->id,
                'type' => 'forum_reply',
                'title' => 'Nouvelle réponse à votre topic',
                'message' => 'Quelqu\'un a répondu à votre discussion.',
                'read_at' => null,
            ],
        ];

        foreach ($notifications as $notifData) {
            Notification::create($notifData);
        }

        echo "   ✓ 3 notifications créées\n\n";

        // 5. Forum
        echo "💬 Création du forum...\n";

        $category = ForumCategory::firstOrCreate(['name' => 'Questions Générales'], [
            'description' => 'Posez vos questions sur les cours',
            'order' => 1,
        ]);

        $topic1 = ForumTopic::firstOrCreate(['title' => 'Comment débuter en programmation ?'], [
            'user_id' => $etudiant1->id,
            'category_id' => $category->id,
            'content' => 'Bonjour, je suis nouveau en programmation. Par où commencer ?',
            'is_pinned' => false,
            'is_locked' => false,
        ]);

        ForumPost::firstOrCreate(['topic_id' => $topic1->id, 'user_id' => $enseignant1->id], [
            'content' => 'Bienvenue ! Je vous recommande de commencer par le cours "Introduction à la Programmation" et de pratiquer régulièrement.',
        ]);

        $topic2 = ForumTopic::firstOrCreate(['title' => 'Aide sur les matrices'], [
            'user_id' => $etudiant2->id,
            'category_id' => $category->id,
            'content' => 'J\'ai du mal à comprendre les opérations matricielles. Quelqu\'un peut m\'aider ?',
            'is_pinned' => false,
            'is_locked' => false,
        ]);

        echo "   ✓ Forum créé avec 2 topics\n\n";

        // Résumé
        echo "✅ TERMINÉ !\n\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📊 COMPTES DE TEST CRÉÉS :\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        echo "👨‍🎓 ÉTUDIANTS :\n";
        echo "   • marie.dupont@test.com / password\n";
        echo "   • jean.martin@test.com / password\n\n";

        echo "👨‍🏫 ENSEIGNANTS :\n";
        echo "   • prof.leclerc@test.com / password\n";
        echo "   • dr.bernard@test.com / password\n\n";

        echo "📚 CONTENU :\n";
        echo "   • 5 leçons (4 publiées, 1 brouillon)\n";
        echo "   • 2 quiz avec questions\n";
        echo "   • 3 notifications\n";
        echo "   • 1 catégorie forum avec 2 discussions\n\n";

        echo "🚀 Connectez-vous et testez l'application !\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    }
}
