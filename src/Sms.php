<?php namespace PhoneCom\Sdk;

use PhoneCom\Mason\Builder\Child;
use PhoneCom\Sdk\Models\Model;

class Sms extends Model
{
    protected $pathInfo = '/sms';

    /**
     * @return array List of SMS test messages created
     */
    public static function create(array $attributes = [])
    {
        $model = new static($attributes);

        return $model->save();
    }

    public function save()
    {
        if (empty($this->attributes['id'])) {
            return $this->hydrate($this->newQuery()->insert($this->attributes)[0]->items);
        }

        return $this->newQuery()->where('id', 'eq', $this->attributes['id'])->update($this->attributes);
    }

    public function toFullMason()
    {
        return (new Child([
                'id' => (int)$this->id,
                'created' => ($this->created === null ? null : (float)$this->created),
                'scheduled' => ($this->scheduled === null ? null : (int)$this->scheduled),
                'direction' => $this->direction,
                'from' => $this->from,
                'to' => $this->to,
                'content' => $this->content
            ]))
            ->setControl('self', ['href' => $this->getSelfUrl()]);
    }
}
