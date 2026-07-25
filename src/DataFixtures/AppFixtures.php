<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Bid;
use App\Entity\CalendarEvent;
use App\Entity\ClientProfile;
use App\Entity\Notification;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\RequestQuestion;
use App\Entity\Review;
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

        $cordobaPoints = [
            ['lat' => 37.888175, 'lng' => -4.779383], // Tendillas
            ['lat' => 37.891556, 'lng' => -4.783155], // Vial Norte
            ['lat' => 37.880456, 'lng' => -4.789456], // Ciudad Jardín
            ['lat' => 37.876123, 'lng' => -4.782345], // Zoco
            ['lat' => 37.905678, 'lng' => -4.792345], // Brillante
            ['lat' => 37.868901, 'lng' => -4.775678], // Sector Sur
            ['lat' => 37.884567, 'lng' => -4.761234], // Fátima
            ['lat' => 37.892345, 'lng' => -4.752345], // Santa Rosa
        ];

        $availableSkills = array_map(fn($c) => $c->value, Category::cases());
        $allPros = [];
        $proPros = [];
        $solverPros = [];

        for ($i = 0; $i < 15; $i++) {
            $user = new User();
            $remainder = $i % 3;
            $baseRoles = ['ROLE_USER', 'ROLE_PROFESSIONAL'];

            if ($remainder === 0) {
                $roles = array_merge($baseRoles, ['ROLE_PRO']);
                $user->setEmail("pro_master_$i@test.com");
                $group = &$proPros;
            } elseif ($remainder === 1) {
                $roles = array_merge($baseRoles, ['ROLE_SOLVER']);
                $user->setEmail("pro_solver_$i@test.com");
                $group = &$solverPros;
            } else {
                $roles = array_merge($baseRoles, ['ROLE_FREE']);
                $user->setEmail("pro_free_$i@test.com");
                $group = null;
            }

            $user->setRoles($roles);
            $user->setPassword($this->hasher->hashPassword($user, 'password'));
            $user->setVerifiedEmail(true);

            $clientProfile = new ClientProfile();
            $clientProfile->setFullName($faker->name());
            $clientProfile->setPhoneNumber($faker->phoneNumber());
            $user->setClientProfile($clientProfile);

            $proProfile = new ProfessionalProfile();
            $proProfile->setFullName($faker->company());
            $proProfile->setPhoneNumber($faker->phoneNumber());
            $skillCount = $faker->numberBetween(3, min(5, \count($availableSkills)));
            $proProfile->setSkills($faker->randomElements($availableSkills, $skillCount));
            $proProfile->setIsVerified(true);
            $proProfile->setAddress($faker->streetAddress() . ", Córdoba");
            $proProfile->setServiceRadiusKm(rand(10, 40));

            $p = $cordobaPoints[array_rand($cordobaPoints)];
            $proProfile->setLocationPoint(new Point($p['lng'], $p['lat']));
            $proProfile->setVerifiedPhone(true);
            $clientProfile->setVerifiedPhone(true);

            // Solo planes de pago (SOLVER/PRO) tienen periodo activo.
            if (!in_array('ROLE_FREE', $roles, true)) {
                $proProfile->setPaidThroughAt(new \DateTimeImmutable('+' . rand(15, 90) . ' days'));
            }

            // Para PRO: CIF debe estar verificado (regla Free/Solver vs Pro).
            if (in_array('ROLE_PRO', $roles, true)) {
                $proProfile->setTaxId('A58818501'); // Ejemplo válido de CIF
                $proProfile->setVerifiedTaxId(true);
            }

            $user->setProfessionalProfile($proProfile);

            $manager->persist($user);
            $manager->persist($clientProfile);
            $manager->persist($proProfile);

            $allPros[] = $proProfile;
            if ($group !== null) {
                $group[] = $proProfile;
            }
        }

        $allClients = [];
        $completedRequests = []; // Para crear reviews después
        $wonJobs = []; // Trabajos ACCEPTED/COMPLETED con profesional asignado → CalendarEvent

        $estimatedExecutionOptions = [
            'Hoy mismo',
            'Mañana',
            'Esta semana',
            'La próxima semana',
            'En dos semanas o más',
            'A convenir al aceptar la oferta',
        ];
        $estimatedExecutionIndex = 0;

        $desiredExecutionOptions = [
            'Lo antes posible',
            'Esta semana',
            'La próxima semana',
            'A convenir al aceptar la oferta',
        ];
        $desiredExecutionIndex = 0;

        for ($i = 0; $i < 10; $i++) {
            $user = new User();
            $user->setEmail("client$i@test.com");
            $user->setRoles(['ROLE_USER']);
            $user->setPassword($this->hasher->hashPassword($user, 'password'));
            $user->setVerifiedEmail(true);

            $clientProfile = new ClientProfile();
            $clientProfile->setFullName($faker->name());
            $clientProfile->setPhoneNumber($faker->phoneNumber());
            $clientProfile->setVerifiedPhone(true);
            $user->setClientProfile($clientProfile);

            $manager->persist($user);
            $manager->persist($clientProfile);
            $allClients[] = ['user' => $user, 'clientProfile' => $clientProfile];

            $numRequests = rand(2, 4);
            for ($j = 0; $j < $numRequests; $j++) {
                $request = new Request();
                $request->setClient($clientProfile);

                $category = Category::cases()[array_rand(Category::cases())];
                $request->setCategory($category);
                $request->setTitle("Servicio de " . $category->value . " en Córdoba");
                $request->setDescription($faker->text(120));

                $risk = $faker->randomElement(RiskLevel::cases());
                $request->setRiskLevel($risk);
                $pricingType = match (true) {
                    $risk === RiskLevel::HIGH && $faker->boolean(60) => Request::PRICING_TYPE_VISIT_REQUIRED,
                    $faker->boolean(35) => Request::PRICING_TYPE_RANGE,
                    default => Request::PRICING_TYPE_FIXED,
                };
                $request->setPricingType($pricingType);
                // estimated_price_* se guarda en céntimos (enteros).
                // Generamos un rango con margen para que min/max no sean idénticos.
                $basePriceCents = $risk === RiskLevel::HIGH ? rand(1500, 4000) : rand(60, 350);
                $minCents = (int) \round($basePriceCents * 0.8);
                $maxCents = (int) \round($basePriceCents * 1.2);
                if ($minCents < 0) {
                    $minCents = 0;
                }
                if ($maxCents < $minCents) {
                    $maxCents = $minCents;
                }
                $request->setEstimatedPriceMin($minCents);
                $request->setEstimatedPriceMax($maxCents);
                $request->setAiDiagnosis([
                    'pricing_type' => $pricingType,
                    'safe' => true,
                    'safety_reason' => null,
                    'estimated_price_min' => $minCents,
                    'estimated_price_max' => $maxCents,
                ]);
                $request->setAddress($faker->streetAddress() . ", Córdoba");
                if ($faker->boolean(30)) {
                    $request->setPreciseAddress($faker->streetAddress() . ', ' . $faker->buildingNumber() . ', Córdoba');
                }

                $pReq = $cordobaPoints[array_rand($cordobaPoints)];
                $request->setLocationPoint(new Point($pReq['lng'] + 0.002, $pReq['lat'] + 0.002));

                $candidateBidders = ($risk === RiskLevel::HIGH && count($proPros) > 0) ? $proPros : $allPros;
                $eligibleBidders = array_values(array_filter(
                    $candidateBidders,
                    static fn(ProfessionalProfile $pro): bool => in_array($category->value, $pro->getSkills(), true)
                ));

                if ($faker->boolean(40)) {
                    // Request con profesional asignado: crear bids primero, luego elegir ganador
                    $numBids = rand(1, 3);
                    $biddersUsed = [];
                    $bids = [];
                    if (count($eligibleBidders) > 0) {
                        for ($k = 0; $k < $numBids; $k++) {
                            $bidder = $eligibleBidders[array_rand($eligibleBidders)];
                            if (in_array($bidder, $biddersUsed, true)) {
                                continue;
                            }
                            $biddersUsed[] = $bidder;
                            $bid = new Bid();
                            $request->addBid($bid);
                            $bid->setProfessional($bidder->getUser());
                            // Oferta del pro: cercana al rango estimado.
                            $bidMin = $request->getEstimatedPriceMin();
                            $bidMax = $request->getEstimatedPriceMax();
                            $bidMid = (int) \round(($bidMin + $bidMax) / 2);
                            if ($pricingType === Request::PRICING_TYPE_RANGE
                                || ($pricingType === Request::PRICING_TYPE_VISIT_REQUIRED && $faker->boolean(50))) {
                                $rangeMin = rand(max(1, $bidMid - 250), max(1, $bidMid - 50));
                                $rangeMax = rand($rangeMin, $rangeMin + 300);
                                $bid->setPricingType(Bid::PRICING_TYPE_RANGE);
                                $bid->setPriceQuoteMin($rangeMin);
                                $bid->setPriceQuoteMax($rangeMax);
                                $bid->setPriceQuote($rangeMin); // compatibilidad legacy
                            } else {
                                $fixed = rand(max(1, $bidMid - 200), max(1, $bidMid + 200));
                                $bid->setPricingType(Bid::PRICING_TYPE_FIXED);
                                $bid->setPriceQuote($fixed);
                                $bid->setPriceQuoteMin($fixed);
                                $bid->setPriceQuoteMax($fixed);
                            }
                            $bid->setStatus(BidStatus::PENDING);
                            $bid->setEstimatedExecutionTime(
                                $estimatedExecutionOptions[$estimatedExecutionIndex % count($estimatedExecutionOptions)]
                            );
                            $estimatedExecutionIndex++;
                            $bids[] = $bid;
                            $manager->persist($bid);
                        }
                    }
                    if (count($bids) > 0) {
                        $winningBid = $bids[array_rand($bids)];
                        $winningBid->setStatus(BidStatus::ACCEPTED);
                        $proProfile = $winningBid->getProfessional()->getProfessionalProfile();
                        $request->setAssignedProfessional($proProfile);
                        $isCompleted = $faker->boolean(35);
                        $request->setStatus($isCompleted ? RequestStatus::COMPLETED : RequestStatus::ACCEPTED);

                        $wonJobs[] = [
                            'request' => $request,
                            'professional' => $proProfile,
                        ];

                        if ($isCompleted) {
                            $completedRequests[] = [
                                'request' => $request,
                                'clientUser' => $user,
                                'proUser' => $winningBid->getProfessional(),
                            ];
                        }
                    } else {
                        $request->setStatus(RequestStatus::PENDING);
                    }
                } else {
                    $request->setStatus(RequestStatus::PENDING);
                    if (count($eligibleBidders) > 0) {
                        $biddersUsed = [];
                        $numBids = min(rand(1, 2), count($eligibleBidders));
                        for ($k = 0; $k < $numBids; $k++) {
                            $remaining = array_values(array_filter(
                                $eligibleBidders,
                                static fn(ProfessionalProfile $pro): bool => !in_array($pro, $biddersUsed, true)
                            ));
                            if ($remaining === []) {
                                break;
                            }
                            $bidder = $remaining[array_rand($remaining)];
                            $biddersUsed[] = $bidder;
                            $bid = new Bid();
                            $request->addBid($bid);
                            $bid->setProfessional($bidder->getUser());
                            // Oferta del pro: cercana al rango estimado.
                            $bidMin = $request->getEstimatedPriceMin();
                            $bidMax = $request->getEstimatedPriceMax();
                            $bidMid = (int) \round(($bidMin + $bidMax) / 2);
                            if ($pricingType === Request::PRICING_TYPE_RANGE
                                || ($pricingType === Request::PRICING_TYPE_VISIT_REQUIRED && $faker->boolean(50))) {
                                $rangeMin = rand(max(1, $bidMid - 250), max(1, $bidMid - 50));
                                $rangeMax = rand($rangeMin, $rangeMin + 300);
                                $bid->setPricingType(Bid::PRICING_TYPE_RANGE);
                                $bid->setPriceQuoteMin($rangeMin);
                                $bid->setPriceQuoteMax($rangeMax);
                                $bid->setPriceQuote($rangeMin); // compatibilidad legacy
                            } else {
                                $fixed = rand(max(1, $bidMid - 200), max(1, $bidMid + 200));
                                $bid->setPricingType(Bid::PRICING_TYPE_FIXED);
                                $bid->setPriceQuote($fixed);
                                $bid->setPriceQuoteMin($fixed);
                                $bid->setPriceQuoteMax($fixed);
                            }
                            $bid->setStatus(BidStatus::PENDING);
                            $bid->setEstimatedExecutionTime(
                                $estimatedExecutionOptions[$estimatedExecutionIndex % count($estimatedExecutionOptions)]
                            );
                            $estimatedExecutionIndex++;
                            $manager->persist($bid);
                        }
                    }
                }

                // Asignar disponibilidad deseada en lugar de fecha exacta
                $request->setDesiredExecutionTime(
                    $desiredExecutionOptions[$desiredExecutionIndex % count($desiredExecutionOptions)]
                );
                $desiredExecutionIndex++;

                $manager->persist($request);
            }
        }

        $manager->flush();

        // Agendar en calendario la mayoría de trabajos ganados (dispersos en el mes actual ± 20 días).
        foreach ($wonJobs as $index => $item) {
            if (!$faker->boolean(75)) {
                continue;
            }

            /** @var Request $wonRequest */
            $wonRequest = $item['request'];
            /** @var ProfessionalProfile $wonPro */
            $wonPro = $item['professional'];
            if ($wonPro === null) {
                continue;
            }

            $dayOffset = ($index % 41) - 20; // -20 .. +20
            $hour = 8 + ($index % 10); // 08:00 .. 17:00
            $startsAt = (new \DateTimeImmutable('today'))
                ->modify(sprintf('%+d days', $dayOffset))
                ->setTime($hour, $index % 2 === 0 ? 0 : 30);

            $event = new CalendarEvent();
            $event->setRequest($wonRequest);
            $event->setProfessional($wonPro);
            $event->setStartsAt($startsAt);
            if ($faker->boolean(30)) {
                $event->setNotes($faker->randomElement([
                    'Llevar herramientas básicas.',
                    'Confirmar acceso con el cliente.',
                    'Presupuesto ya aceptado.',
                ]));
            }
            $manager->persist($event);
        }

        // Crear reviews para solicitudes completadas (cliente valora al profesional)
        foreach ($completedRequests as $item) {
            $request = $item['request'];
            $clientUser = $item['clientUser'];
            $proUser = $item['proUser'];

            $review = new Review();
            $review->setRequest($request);
            $review->setAuthor($clientUser);
            $review->setTarget($proUser);
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

        // Asegurar que las reviews están en BD antes de recalcular ratings
        $manager->flush();

        // Actualizar rating y reviewCount de todos los perfiles (profesionales y clientes) según las reviews existentes
        $reviewRepo = $manager->getRepository(Review::class);
        $userRepo = $manager->getRepository(User::class);
        /** @var User[] $allUsers */
        $allUsers = $userRepo->findAll();

        foreach ($allUsers as $user) {
            $reviewsAsTarget = $reviewRepo->findBy(['target' => $user]);
            if (count($reviewsAsTarget) === 0) {
                continue;
            }

            $proReviews = [];
            $clientReviews = [];
            foreach ($reviewsAsTarget as $review) {
                $author = $review->getAuthor();
                $authorIsPro = $author !== null && (
                    in_array('ROLE_PROFESSIONAL', $author->getRoles(), true)
                    || $author->getProfessionalProfile() !== null
                );
                if ($authorIsPro) {
                    $clientReviews[] = $review;
                } else {
                    $proReviews[] = $review;
                }
            }

            $proProfile = $user->getProfessionalProfile();
            if ($proProfile !== null && count($proReviews) > 0) {
                $avg = round(array_sum(array_map(fn(Review $r) => $r->getScore(), $proReviews)) / count($proReviews), 1);
                $proProfile->setRating((float) $avg);
                $proProfile->setReviewCount(count($proReviews));
                $manager->persist($proProfile);
            }

            $clientProfile = $user->getClientProfile();
            if ($clientProfile !== null && count($clientReviews) > 0) {
                $avg = round(array_sum(array_map(fn(Review $r) => $r->getScore(), $clientReviews)) / count($clientReviews), 1);
                $clientProfile->setRating((float) $avg);
                $clientProfile->setReviewCount(count($clientReviews));
                $manager->persist($clientProfile);
            }
        }

        // Crear RequestQuestions (profesional pregunta al cliente)
        $allRequests = $manager->getRepository(Request::class)->findAll();
        $questionTexts = [
            '¿Hay acceso con ascensor o solo escaleras?',
            '¿Necesita el trabajo para alguna fecha concreta?',
            '¿Hay mascotas en el domicilio?',
            '¿Tienes ya los materiales o necesitas que los compre?',
            '¿Cuánto tiempo llevas con el problema?',
        ];
        foreach ($allRequests as $request) {
            if ($faker->boolean(25)) {
                $proAsking = $allPros[array_rand($allPros)]->getUser();
                $question = new RequestQuestion();
                $question->setRequest($request);
                $question->setAuthor($proAsking);
                $question->setQuestionText($faker->randomElement($questionTexts));
                if ($faker->boolean(60)) {
                    $question->setAnswerText($faker->randomElement([
                        'Sí, hay ascensor.',
                        'Para la próxima semana si es posible.',
                        'No hay mascotas.',
                        'Ya tengo los materiales.',
                        'Unos dos meses aproximadamente.',
                    ]));
                }
                $manager->persist($question);
            }
        }

        // Crear Notificaciones
        $notificationTypes = [
            ['type' => 'BID_RECEIVED', 'title' => 'Nueva oferta', 'message' => 'Has recibido una nueva oferta en tu solicitud.'],
            ['type' => 'BID_ACCEPTED', 'title' => 'Oferta aceptada', 'message' => 'Tu oferta ha sido aceptada.'],
            ['type' => 'QUESTION_RECEIVED', 'title' => 'Nueva pregunta', 'message' => 'Un profesional te ha hecho una pregunta sobre tu solicitud.'],
            ['type' => 'REQUEST_ACTIVITY', 'title' => 'Actividad en solicitud', 'message' => 'Hay nueva actividad en una de tus solicitudes.'],
        ];
        foreach (array_merge(array_column($allClients, 'user'), array_map(fn($p) => $p->getUser(), $allPros)) as $user) {
            $numNotifs = $faker->numberBetween(1, 4);
            for ($n = 0; $n < $numNotifs; $n++) {
                $nt = $faker->randomElement($notificationTypes);
                $notif = new Notification();
                $notif->setUser($user);
                $notif->setTitle($nt['title']);
                $notif->setMessage($nt['message']);
                $notif->setType($nt['type']);
                $notif->setIsRead($faker->boolean(40));
                $notif->setRelatedId($faker->optional(0.6)->numberBetween(1, 50));
                $manager->persist($notif);
            }
        }

        $manager->flush();
    }
}
