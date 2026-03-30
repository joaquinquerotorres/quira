<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\RequestQuestion;
use App\Entity\Review;
use App\Entity\VisitRequest;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\Category;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use LongitudeOne\Spatial\PHP\Types\Geometry\Point;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('es_ES');
        $faker->seed(1234);

        // Coordenadas aproximadas de Córdoba para que ST_Distance_Sphere tenga sentido.
        $cordobaPoints = [
            ['lat' => 37.888175, 'lng' => -4.779383],
            ['lat' => 37.891556, 'lng' => -4.783155],
            ['lat' => 37.880456, 'lng' => -4.789456],
            ['lat' => 37.876123, 'lng' => -4.782345],
            ['lat' => 37.905678, 'lng' => -4.792345],
            ['lat' => 37.868901, 'lng' => -4.775678],
            ['lat' => 37.884567, 'lng' => -4.761234],
            ['lat' => 37.892345, 'lng' => -4.752345],
        ];

        $allSkills = array_map(fn($c) => $c->value, Category::cases());
        $estimatedExecutionOptions = [
            'Hoy mismo',
            'Mañana',
            'Esta semana',
            'La próxima semana',
            'En dos semanas o más',
            'A convenir al aceptar la oferta',
        ];
        $desiredExecutionOptions = [
            'Lo antes posible',
            'Esta semana',
            'La próxima semana',
            'A convenir al aceptar la oferta',
        ];

        $createPro = function (
            string $email,
            array $roles,
            bool $isProTier,
            bool $verifiedTaxId
        ) use ($manager, $faker, $cordobaPoints, $allSkills, $estimatedExecutionOptions): ProfessionalProfile {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->hasher->hashPassword($user, 'password'));
            $user->setRoles($roles);
            $user->setVerifiedEmail(true);

            $profile = new ProfessionalProfile();
            $profile->setFullName($faker->company());
            $profile->setPhoneNumber($faker->phoneNumber());
            $profile->setVerifiedPhone(true);
            $profile->setSkills($allSkills);
            $profile->setIsVerified(true);
            $profile->setAddress($faker->streetAddress() . ', Córdoba');
            $profile->setServiceRadiusKm(500);

            $p = $cordobaPoints[array_rand($cordobaPoints)];
            $profile->setLocationPoint(new Point($p['lng'], $p['lat']));

            // Evita envíos externos desde listeners durante fixture load.
            $profile->setNotifyRequestActivity(false);
            $profile->setNotifyBidActivity(false);
            $profile->setNotifyReviews(false);

            if ($verifiedTaxId) {
                $profile->setTaxId('A58818501');
                $profile->setVerifiedTaxId(true);
            } else {
                $profile->setTaxId(null);
                $profile->setVerifiedTaxId(false);
            }

            if ($isProTier) {
                $profile->setPaidThroughAt(new \DateTimeImmutable('+6 months'));
            } else {
                $profile->setPaidThroughAt(null);
            }

            $user->setProfessionalProfile($profile);
            $profile->setUser($user);

            $manager->persist($user);
            $manager->persist($profile);

            return $profile;
        };

        // 3 pros: FREE / SOLVER / PRO.
        $freePro = $createPro(
            email: 'pro-free@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_FREE'],
            isProTier: false,
            verifiedTaxId: false
        );
        $solverPro = $createPro(
            email: 'pro-solver@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_SOLVER'],
            isProTier: true,
            verifiedTaxId: false
        );
        $proPro = $createPro(
            email: 'pro-pro@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO'],
            isProTier: true,
            verifiedTaxId: true
        );

        $proProfiles = [$freePro, $solverPro, $proPro];

        $createClient = function (string $email, string $phone) use ($manager, $faker): ClientProfile {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->hasher->hashPassword($user, 'password'));
            $user->setRoles(['ROLE_USER']);
            $user->setVerifiedEmail(true);

            $profile = new ClientProfile();
            $profile->setFullName($faker->name());
            $profile->setPhoneNumber($phone);
            $profile->setVerifiedPhone(true);
            $profile->setNotifyRequestActivity(false);
            $profile->setNotifyBidActivity(false);
            $profile->setNotifyReviews(false);

            $user->setClientProfile($profile);
            $profile->setUser($user);

            $manager->persist($user);
            $manager->persist($profile);

            return $profile;
        };

        // 2 clientes.
        $clientProfiles = [
            $createClient('client0@test.com', '+34600000010'),
            $createClient('client1@test.com', '+34600000011'),
        ];

        $requestsPerCategory = [
            RequestStatus::PENDING,
            RequestStatus::ACCEPTED,
            RequestStatus::COMPLETED,
        ];

        $questionTexts = [
            '¿Hay acceso con ascensor o solo escaleras?',
            '¿Necesita el trabajo para alguna fecha concreta?',
            '¿Hay mascotas en el domicilio?',
            '¿Tienes ya los materiales o necesitas que los compre?',
            '¿Cuánto tiempo llevas con el problema?',
        ];

        $answerTexts = [
            'Sí, hay ascensor.',
            'Para la próxima semana si es posible.',
            'No hay mascotas.',
            'Ya tengo los materiales.',
            'Unos dos meses aproximadamente.',
            'Puedo facilitar fotos del problema.',
            'Podemos coordinar una hora por la tarde.',
        ];

        $visitNotes = [
            'Confirmación de visita coordinada con el profesional.',
            'Pendiente de disponibilidad horaria.',
            'Se revisará el alcance y se propondrá plan de actuación.',
            'Requiere revisión en el domicilio para concretar el presupuesto.',
        ];

        $clientIdx = 0;
        foreach (Category::cases() as $catIdx => $category) {
            foreach ($requestsPerCategory as $reqIdx => $status) {
                $clientProfile = $clientProfiles[$clientIdx % count($clientProfiles)];
                $clientIdx++;

                $request = new Request();
                $request->setClient($clientProfile);
                $request->setCategory($category);
                $request->setTitle('Servicio de ' . $category->value . ' en Córdoba');
                $request->setDescription($faker->text(80));
                $request->setRiskLevel(RiskLevel::LOW);

                // Rangos estimados en céntimos con margen (min != max).
                $base = 6000 + ($catIdx * 700) + ($reqIdx * 200); // céntimos
                $min = (int) \round($base * 0.8);
                $max = (int) \round($base * 1.2);
                if ($min < 0) {
                    $min = 0;
                }
                if ($max < $min) {
                    $max = $min;
                }
                if ($min === $max) {
                    $max = $min + 1;
                }

                $request->setEstimatedPriceMin($min);
                $request->setEstimatedPriceMax($max);

                $request->setAddress($faker->streetAddress() . ', Córdoba');
                $pReq = $cordobaPoints[($catIdx + $reqIdx) % count($cordobaPoints)];
                $request->setLocationPoint(new Point($pReq['lng'], $pReq['lat']));
                $request->setDesiredExecutionTime($desiredExecutionOptions[($catIdx + $reqIdx) % count($desiredExecutionOptions)]);

                $winningPro = $proProfiles[($catIdx + $reqIdx) % count($proProfiles)];

                if ($status === RequestStatus::PENDING) {
                    $request->setStatus(RequestStatus::PENDING);
                    // Un bid pendiente (sin asignación).
                    $bid = new Bid();
                    $bid->setProfessional($winningPro->getUser());
                    $bid->setPriceQuote($min + 100);
                    $bid->setStatus(BidStatus::PENDING);
                    $bid->setEstimatedExecutionTime($estimatedExecutionOptions[($catIdx) % count($estimatedExecutionOptions)]);

                    $request->addBid($bid);

                    $manager->persist($bid);

                    // Pregunta al cliente (sin respuesta obligatoria para PENDING).
                    $question = new RequestQuestion();
                    $question->setRequest($request);
                    $question->setAuthor($winningPro->getUser());
                    $question->setQuestionText($faker->randomElement($questionTexts));
                    if ($faker->boolean(45)) {
                        $question->setAnswerText($faker->randomElement($answerTexts));
                    }
                    $manager->persist($question);

                    // Visita solicitada por el profesional (aunque aún no esté asignada la request).
                    $visitRequest = new VisitRequest();
                    $visitRequest->setRequest($request);
                    $visitRequest->setProfessional($winningPro);
                    $visitRequest->setStatus(VisitRequest::STATUS_PENDING);
                    $visitRequest->setNote($faker->randomElement($visitNotes));
                    $manager->persist($visitRequest);
                } else {
                    // Crear 2 bids: una aceptada (ganadora) y otra rechazada.
                    $acceptedBid = new Bid();
                    $acceptedBid->setProfessional($winningPro->getUser());
                    $acceptedBid->setPriceQuote((int) \round(($min + $max) / 2));
                    $acceptedBid->setStatus(BidStatus::ACCEPTED);
                    $acceptedBid->setEstimatedExecutionTime($estimatedExecutionOptions[($catIdx) % count($estimatedExecutionOptions)]);

                    $rejectedBid = new Bid();
                    $rejectedBid->setProfessional($proProfiles[($catIdx + 1) % count($proProfiles)]->getUser());
                    $rejectedBid->setPriceQuote((int) \round(($min + $max) / 2) - 300);
                    if ($rejectedBid->getPriceQuote() < 0) {
                        $rejectedBid->setPriceQuote(0);
                    }
                    $rejectedBid->setStatus(BidStatus::REJECTED);
                    $rejectedBid->setEstimatedExecutionTime($estimatedExecutionOptions[($catIdx + 1) % count($estimatedExecutionOptions)]);

                    $request->addBid($acceptedBid);
                    $request->addBid($rejectedBid);

                    $manager->persist($acceptedBid);
                    $manager->persist($rejectedBid);

                    $request->setAssignedProfessional($winningPro);
                    $request->setStatus($status);

                    // Pregunta al cliente con respuesta probabilística.
                    $question = new RequestQuestion();
                    $question->setRequest($request);
                    $question->setAuthor($acceptedBid->getProfessional());
                    $question->setQuestionText($faker->randomElement($questionTexts));
                    if ($status === RequestStatus::COMPLETED || $faker->boolean(55)) {
                        $question->setAnswerText($faker->randomElement($answerTexts));
                    }
                    $manager->persist($question);

                    // Visita del profesional con estado acorde a si la request está cerrada.
                    $visitRequest = new VisitRequest();
                    $visitRequest->setRequest($request);
                    $visitRequest->setProfessional($winningPro);
                    if ($status === RequestStatus::COMPLETED) {
                        $visitRequest->setStatus(VisitRequest::STATUS_ACCEPTED);
                        $visitRequest->setNote('Visita confirmada y trabajo realizado.');
                    } else {
                        $visitRequest->setStatus(VisitRequest::STATUS_PENDING);
                        $visitRequest->setNote($faker->randomElement($visitNotes));
                    }
                    $manager->persist($visitRequest);

                    // Review: solo para solicitudes cerradas (COMPLETED).
                    if ($status === RequestStatus::COMPLETED) {
                        $review = new Review();
                        $review->setRequest($request);
                        $review->setAuthor($clientProfile->getUser());
                        $review->setTarget($winningPro->getUser());
                        $review->setScore($faker->numberBetween(3, 5));
                        $review->setComment($faker->optional(0.7)->randomElement([
                            'Excelente trabajo, muy recomendable.',
                            'Muy profesional y puntual. Todo perfecto.',
                            'Muy satisfecho con el servicio.',
                            'Buen profesional, lo volvería a contratar.',
                            'Correcto, resolvió el problema.',
                        ]));
                        $manager->persist($review);
                    }
                }

                $manager->persist($request);
            }
        }

        $manager->flush();
    }
}
