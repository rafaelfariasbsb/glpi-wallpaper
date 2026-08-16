<?php

/**
 * Aba do plugin dentro de Administracao > Perfis.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Wallpaper;

use CommonGLPI;
use Html;
use Session;

class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return Wallpaper::getTypeName($nb);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && $item->getField('id')) {
            return self::createTabEntry(Wallpaper::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            $profile = new self();
            $profile->showForm((int) $item->getField('id'));
        }
        return true;
    }

    public function showForm($profiles_id = 0, $options = [])
    {
        if (!self::canView()) {
            return false;
        }

        $profile = new \Profile();
        $profile->getFromDB((int) $profiles_id);

        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='firstbloc'>";
        if ($canedit) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $matrix_options = [
            'canedit' => $canedit,
            'title'   => Wallpaper::getTypeName(),
        ];

        $this->displayRightsChoiceMatrix([
            [
                'itemtype' => Wallpaper::class,
                'label'    => Wallpaper::getTypeName(),
                'field'    => Wallpaper::$rightname,
            ],
        ], $matrix_options);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';

        return true;
    }
}
