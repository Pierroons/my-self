<?php
/**
 * SelfRecover demo — mini wordlist for diceware passphrase generation.
 *
 * 256 short memorable words (3-7 letters). Entropy for a 4-word passphrase:
 *   log2(256^4) = 32 bits
 * That's **deliberately low** for a DEMO (easier to read, remember, type).
 * The real SelfRecover implementation uses the EFF large wordlist
 * (7776 words, 4 words = 51 bits of entropy).
 *
 * In the demo logs we explicitly flag this as "demo wordlist, 32 bits entropy,
 * reference EFF list gives 51 bits".
 */

declare(strict_types=1);

final class DicewareWordlist {
    public const ENTROPY_BITS_PER_WORD = 8;
    public const IS_DEMO_LIST = true;

    public const WORDS = [
        'able','acid','arm','army','axe','bag','bake','ball','band','bank',
        'bar','bath','beach','bean','beat','bed','bee','beer','bell','belt',
        'bench','bent','berry','best','bid','big','bike','bill','bin','bird',
        'bit','bite','black','blade','blast','blow','blue','board','boat','body',
        'boil','bold','bone','book','boot','box','boy','brain','branch','brass',
        'brave','bread','break','brick','bride','bright','bring','broad','broke','brown',
        'brush','buck','bud','built','bull','burn','burst','bus','bush','busy',
        'butt','butter','button','buy','cab','cake','calf','call','calm','came',
        'camp','can','canal','cap','cape','car','card','care','cart','case',
        'cash','cast','cat','catch','caught','cave','cell','cent','chain','chair',
        'chalk','champ','change','charm','chase','cheap','cheek','cheer','chess','chest',
        'chief','child','chill','chin','chip','chop','cider','civic','claim','clamp',
        'clap','clash','class','claw','clay','clean','clear','clerk','cliff','climb',
        'cling','clip','cloak','clock','clone','close','cloth','cloud','clown','club',
        'coach','coal','coast','coat','cobra','code','coffee','coin','cold','colt',
        'come','comic','cone','cook','cool','cope','copy','cord','cork','corn',
        'cost','cot','couch','cough','count','court','cover','cow','crab','crack',
        'craft','crane','crash','crate','crawl','crazy','cream','creek','crew','crib',
        'crime','crisp','crop','cross','crow','crowd','crown','crude','cruel','crush',
        'crust','cry','cube','cult','cup','curb','cure','curl','curse','curve',
        'cut','dance','dare','dark','dart','dash','date','dawn','day','dead',
        'deaf','deal','dear','debt','deck','deep','deer','delay','den','dent',
        'desk','diet','dig','dim','dine','dip','dirt','dish','dive','dock',
        'doll','done','door','dose','dot','dough','dove','down','drag','drain',
        'draw','dream','dress','drift','drill','drink','drip','drive','drop','drum',
        'dry','duck','dull','dump','dune','dusk','dust','duty','eagle','early',
    ];

    /**
     * @return array{words: string[], entropy_bits: int, is_demo: bool}
     */
    public static function generate(int $count = 4): array {
        $words = [];
        $max = count(self::WORDS) - 1;
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[random_int(0, $max)];
        }
        return [
            'words'        => $words,
            'entropy_bits' => $count * self::ENTROPY_BITS_PER_WORD,
            'is_demo'      => self::IS_DEMO_LIST,
        ];
    }
}
