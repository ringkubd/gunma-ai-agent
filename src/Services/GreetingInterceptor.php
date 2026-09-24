<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

class GreetingInterceptor
{
    private const GREETINGS = [
        'hi'         => null,
        'hello'      => null,
        'hey'        => null,
        'hey there'  => null,
        'good morning' => null,
        'good afternoon' => null,
        'good evening' => null,
        'asalam o alikum' => null,
        'assalamu alaikum' => null,
        'salam'      => null,
        'thank you'  => 'thanks',
        'thanks'     => 'thanks',
        'ty'         => 'thanks',
        'bye'        => 'bye',
        'goodbye'    => 'bye',
    ];

    /**
     * Localized greeting templates per language code.
     * Placeholders: {time} {name} — the name segment already includes a leading space.
     */
    private const TEMPLATES = [
        'bn' => [
            'returning' => '{time}, {name}! Gunma Halal Food-এ আবার স্বাগতম। আজ আপনাকে কীভাবে সাহায্য করতে পারি?',
            'named'     => '{time}, {name}! আমি পিকু, Gunma Halal Food থেকে। আজ কীভাবে সাহায্য করতে পারি?',
            'guest'     => '{time}! আমি পিকু, Gunma Halal Food কাস্টমার সাপোর্ট থেকে। আজ কীভাবে সাহায্য করতে পারি?',
            'time'      => ['শুভ সকাল', 'শুভ অপরাহ্ন', 'শুভ সন্ধ্যা'],
        ],
        'hi' => [
            'returning' => '{time}, {name}! Gunma Halal Food में आपका फिर से स्वागत है। मैं आपकी कैसे मदद कर सकता हूँ?',
            'named'     => '{time}, {name}! मैं पीकू हूँ, Gunma Halal Food से। आज मैं आपकी कैसे मदद करूँ?',
            'guest'     => '{time}! मैं पीकू हूँ, Gunma Halal Food ग्राहक सहायता से। आज मैं आपकी कैसे मदद करूँ?',
            'time'      => ['सुप्रभात', 'नमस्कार', 'शुभ संध्या'],
        ],
        'ur' => [
            'returning' => '{time}، {name}! Gunma Halal Food میں دوبارہ خوش آمدید۔ میں آپ کی کیسے مدد کر سکتا ہوں؟',
            'named'     => '{time}، {name}! میں پیکو ہوں، Gunma Halal Food سے۔ آج میں آپ کی کیسے مدد کر سکتا ہوں؟',
            'guest'     => '{time}! میں پیکو ہوں، Gunma Halal Food کسٹمر سپورٹ سے۔ آج میں آپ کی کیسے مدد کر سکتا ہوں؟',
            'time'      => ['صبح بخیر', 'السلام علیکم', 'شب بخیر'],
        ],
        'pa' => [
            'returning' => '{time}, {name}! Gunma Halal Food ਵਿੱਚ ਦੁਬਾਰਾ ਜੀ ਆਇਆਂ ਨੂੰ। ਮੈਂ ਤੁਹਾਡੀ ਕਿਵੇਂ ਮਦਦ ਕਰ ਸਕਦਾ ਹਾਂ?',
            'named'     => '{time}, {name}! ਮੈਂ ਪੀਕੂ ਹਾਂ, Gunma Halal Food ਤੋਂ। ਅੱਜ ਮੈਂ ਤੁਹਾਡੀ ਕਿਵੇਂ ਮਦਦ ਕਰਾਂ?',
            'guest'     => '{time}! ਮੈਂ ਪੀਕੂ ਹਾਂ, Gunma Halal Food ਗਾਹਕ ਸਹਾਇਤਾ ਤੋਂ। ਮੈਂ ਤੁਹਾਡੀ ਕਿਵੇਂ ਮਦਦ ਕਰਾਂ?',
            'time'      => ['ਸ਼ੁਭ ਸਵੇਰ', 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ', 'ਸ਼ੁਭ ਸ਼ਾਮ'],
        ],
        'gu' => [
            'returning' => '{time}, {name}! Gunma Halal Food માં ફરી સ્વાગત છે. હું આપની કેવી રીતે મદદ કરી શકું?',
            'named'     => '{time}, {name}! હું પીકુ છું, Gunma Halal Food તરફથી. આજે હું આપની કેવી રીતે મદદ કરું?',
            'guest'     => '{time}! હું પીકુ છું, Gunma Halal Food ગ્રાહક સહાય તરફથી. હું આપની કેવી રીતે મદદ કરું?',
            'time'      => ['સુપ્રભાત', 'નમસ્તે', 'શુભ સાંજ'],
        ],
        'ta' => [
            'returning' => '{time}, {name}! Gunma Halal Food-க்கு மீண்டும் வரவேற்கிறோம். நான் எப்படி உதவலாம்?',
            'named'     => '{time}, {name}! நான் பீகு, Gunma Halal Food-இல் இருந்து. இன்று எப்படி உதவலாம்?',
            'guest'     => '{time}! நான் பீகு, Gunma Halal Food வாடிக்கையாளர் ஆதரவில் இருந்து. எப்படி உதவலாம்?',
            'time'      => ['காலை வணக்கம்', 'வணக்கம்', 'மாலை வணக்கம்'],
        ],
        'te' => [
            'returning' => '{time}, {name}! Gunma Halal Food-కు తిరిగి స్వాగతం. నేను ఎలా సహాయం చేయగలను?',
            'named'     => '{time}, {name}! నేను పీకు, Gunma Halal Food నుండి. ఈరోజు ఎలా సహాయం చేయగలను?',
            'guest'     => '{time}! నేను పీకు, Gunma Halal Food కస్టమర్ సపోర్ట్ నుండి. ఎలా సహాయం చేయగలను?',
            'time'      => ['శుభోదయం', 'నమస్కారం', 'శుభ సాయంత్రం'],
        ],
        'ml' => [
            'returning' => '{time}, {name}! Gunma Halal Food-ലേക്ക് വീണ്ടും സ്വാഗതം. ഞാൻ എങ്ങനെ സഹായിക്കാം?',
            'named'     => '{time}, {name}! ഞാൻ പീകു, Gunma Halal Food-ൽ നിന്ന്. ഇന്ന് എങ്ങനെ സഹായിക്കാം?',
            'guest'     => '{time}! ഞാൻ പീകു, Gunma Halal Food കസ്റ്റമർ സപ്പോർട്ടിൽ നിന്ന്. എങ്ങനെ സഹായിക്കാം?',
            'time'      => ['സുപ്രഭാതം', 'നമസ്കാരം', 'ശുഭ സായാഹ്നം'],
        ],
        'si' => [
            'returning' => '{time}, {name}! Gunma Halal Food වෙත නැවත සාදරයෙන් පිළිගනිමු. මම කෙසේ උදව් කරන්නද?',
            'named'     => '{time}, {name}! මම පීකු, Gunma Halal Food වෙතින්. අද මම කෙසේ උදව් කරන්නද?',
            'guest'     => '{time}! මම පීකු, Gunma Halal Food පාරිභෝගික සහායෙන්. මම කෙසේ උදව් කරන්නද?',
            'time'      => ['සුබ උදෑසනක්', 'ආයුබෝවන්', 'සුබ සැන්දෑවක්'],
        ],
        'ne' => [
            'returning' => '{time}, {name}! Gunma Halal Food मा फेरि स्वागत छ। म कसरी सहयोग गर्न सक्छु?',
            'named'     => '{time}, {name}! म पिकु हुँ, Gunma Halal Food बाट। आज म कसरी सहयोग गरूँ?',
            'guest'     => '{time}! म पिकु हूँ, Gunma Halal Food ग्राहक सहयोगबाट। म कसरी सहयोग गरूँ?',
            'time'      => ['शुभ बिहान', 'नमस्ते', 'शुभ साँझ'],
        ],
        'ja' => [
            'returning' => '{time}、{name}さん！Gunma Halal Food へおかえりなさい。本日はどのようなご用件でしょうか？',
            'named'     => '{time}、{name}さん！ピクです、Gunma Halal Food より。本日はどのようにお手伝いしましょうか？',
            'guest'     => '{time}！ピクです、Gunma Halal Food カスタマーサポートより。本日はどのようにお手伝いしましょうか？',
            'time'      => ['おはようございます', 'こんにちは', 'こんばんは'],
        ],
    ];

    public function intercept(string $query, ?array $userContext = null): ?string
    {
        $clean = strtolower(trim(preg_replace('/[?!.,]/', '', $query)));
        $type = self::GREETINGS[$clean] ?? null;

        // Direct greeting match
        if ($type === null && array_key_exists($clean, self::GREETINGS)) {
            return $this->buildGreeting($userContext);
        }

        if ($type === 'thanks') {
            return $this->buildThanks($userContext);
        }

        if ($type === 'bye') {
            return $this->buildBye($userContext);
        }

        return null;
    }

    private function buildGreeting(?array $ctx): string
    {
        $hour = (int) date('H');
        $timeIndex = $hour < 12 ? 0 : ($hour < 17 ? 1 : 2);

        $code = strtolower((string) ($ctx['language_code'] ?? 'en'));
        $primary = explode('-', $code)[0];
        $tpl = self::TEMPLATES[$primary] ?? null;

        $name = $ctx['name'] ?? null;
        $isReturning = ($ctx['previous_orders'] ?? 0) > 0;

        // English fallback (also used when the preference is English).
        if ($tpl === null) {
            $timeGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
            $nameSegment = $name ? ", {$name}" : '';

            if ($name && $isReturning) {
                return "{$timeGreeting}{$nameSegment}! Welcome back to Gunma Halal Food. How can I help you today?";
            }
            if ($name) {
                return "{$timeGreeting}{$nameSegment}! This is Piku from Gunma Halal Food. How may I assist you today?";
            }
            return "{$timeGreeting}! This is Piku from Gunma Halal Food Customer Support. How may I assist you today?";
        }

        $time = $tpl['time'][$timeIndex];

        $sentence = $name && $isReturning
            ? $tpl['returning']
            : ($name ? $tpl['named'] : $tpl['guest']);

        // {name} is replaced with the raw name (templates already include the comma/space).
        return str_replace(['{time}', '{name}'], [$time, (string) $name], $sentence);
    }

    private function buildThanks(?array $ctx): string
    {
        $primary = explode('-', strtolower((string) ($ctx['language_code'] ?? 'en')))[0];
        $name = $ctx['name'] ?? null;
        $nameSegment = $name ? " {$name}" : '';
        return match ($primary) {
            'bn' => "আপনাকে অনেক স্বাগতম{$nameSegment}! আর কিছু লাগলে বলুন।",
            'hi' => "आपका बहुत स्वागत है{$nameSegment}! और कुछ चाहिए तो बताइए।",
            'ur' => "آپ کو بہت خوش آمدید{$nameSegment}! کچھ اور چاہیے تو بتائیں۔",
            'ja' => "どういたしまして{$nameSegment}！他にご用件があればお知らせください。",
            default => "You're very welcome{$nameSegment}! Let me know if you need anything else.",
        };
    }

    private function buildBye(?array $ctx): string
    {
        $primary = explode('-', strtolower((string) ($ctx['language_code'] ?? 'en')))[0];
        $name = $ctx['name'] ?? null;
        $nameSegment = $name ? " {$name}" : '';
        return match ($primary) {
            'bn' => "বিদায়{$nameSegment}! ভালো থাকুন, আবার আসবেন!",
            'hi' => "अलविदा{$nameSegment}! अच्छे रहें, फिर आइएगा!",
            'ur' => "خدا حافظ{$nameSegment}! اچھے رہیں، دوبارہ آئیں!",
            'ja' => "さようなら{$nameSegment}！またお越しください！",
            default => "Goodbye{$nameSegment}! Have a great day and come back soon!",
        };
    }
}
