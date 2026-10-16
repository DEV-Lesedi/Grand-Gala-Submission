import { useRef, useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import Table from '../../../components/Table'
import StaffPageHeader from '../components/StaffPageHeader'
import Toggle from '../components/Toggle'
import { panelSlidesOver } from '../components/panelSlidesOver'
import AddAmenityForm from './AddAmenityForm'
import PromotionForm from './PromotionForm'
import { isCurrentOrUpcoming } from './promotionHelpers'
import AddSpaceForm from './AddSpaceForm'
import AmenitiesCard from './AmenitiesCard'
import PromotionsCard from './PromotionsCard'
import SpaceEditor from './SpaceEditor'
import SpaceListCard from './SpaceListCard'
import { blackoutToday, blackoutsFor, currentRateText, typeLabel, usageText } from './spaceHelpers'
import { SPACES_INFO } from '../../../../lib/staff/spacesData'
import { formatDate } from '../../../../lib/dates'
import { loadedText, useApiData } from '../../../hooks/useApiData'
import { useSave } from '../../../hooks/useSave'
import * as api from '../../../../services/staffSpaces'
import './SpacesAndRatesPage.css'

// Each save reloads the page. A refusal shows its reason at the top.
export default function SpacesAndRatesPage() {
  const loaded = useApiData(api.loadSpacesPage, [])
  const spaces = loaded.data?.spaces ?? []
  const amenities = loaded.data?.amenities ?? []
  const promotions = loaded.data?.promotions ?? []
  // One change, then reload. Returns the answer, or null (reason is in problem).
  const { save, problem } = useSave(loaded.reload)
  const [editingPromotion, setEditingPromotion] = useState(null) // the promotion being edited, or null (adding)
  const [selectedId, setSelectedId] = useState(null) // null = the first space
  const [isEditorOpen, setIsEditorOpen] = useState(false) // only used below 1280px, where the editor slides over
  const [openPopup, setOpenPopup] = useState(null) // null, 'space', 'amenity' or 'promotion'

  const selectedSpace = spaces.find((space) => space.id === selectedId) || spaces[0]
  const selectedRooms = selectedSpace ? spaces.filter((space) => space.parentId === selectedSpace.id) : []
  // Ended promotions are hidden (codes stay taken)
  const visiblePromotions = promotions.filter(isCurrentOrUpcoming)

  function updateSpace(id, changes) {
    save(() => api.updateSpace(id, changes))
  }

  async function addSpace(space) {
    const saved = await save(() => api.addSpace({ ...space, venueId: spaces[0]?.venueId }))
    if (saved) setSelectedId(saved.id) // show the new space in the editor, ready for its first rate
  }

  // One blackout for several spaces (one row per space, in one transaction on the API's side)
  function addBlackout(spaceIds, blackout) {
    save(() => api.addBlackout(spaceIds, blackout))
  }

  function removeBlackout(spaceId, blackoutId) {
    save(() => api.removeBlackout(blackoutId))
  }

  function rateAction(action, spaceId, rate) {
    if (action === 'add') save(() => api.addRate(spaceId, rate))
    if (action === 'cancel') save(() => api.cancelRate(rate.id))
    if (action === 'end') save(() => api.endRateNow(rate.id))
  }

  function toggleAmenity(id, isActive) {
    save(() => api.updateAmenity(id, { isActive }))
  }

  function openPromotionForm(promo) {
    setEditingPromotion(promo)
    setOpenPopup('promotion')
  }

  function savePromotion(saved) {
    save(() => api.savePromotion(saved, !editingPromotion))
  }

  function togglePromotion(id, isActive) {
    save(() => api.setPromotionActive(id, isActive))
  }

  // Unsaved-changes flag. A ref, so toggling it doesn't redraw.
  const hasUnsavedChanges = useRef(false)

  function setUnsavedChanges(value) {
    hasUnsavedChanges.current = value
  }

  function openSpace(space) {
    // Opening another space would lose the editor's unsaved changes, so ask first
    const isOtherSpace = space.id !== selectedSpace?.id
    if (isOtherSpace && hasUnsavedChanges.current && !window.confirm(`Discard unsaved changes to ${selectedSpace.name}?`)) return
    setSelectedId(space.id)
    if (panelSlidesOver()) setIsEditorOpen(true)
  }

  const spaceColumns = [
    {
      key: 'name',
      header: 'Space',
      render: (space) => {
        const blackout = blackoutToday(space, spaces)
        return (
          <span className="spaces-name-cell">
            <img src={space.photo} alt="" />
            <span>
              <strong>{space.name}</strong>
              {/* Can't be booked today, even though it's switched on */}
              {blackout && <span className="spaces-blackout-badge">Blacked out until {formatDate(blackout.end)}</span>}
            </span>
          </span>
        )
      },
    },
    { key: 'type', header: 'Type', render: (space) => typeLabel(space.type) },
    { key: 'capacity', header: 'Capacity', render: (space) => `${space.capacity} guests` },
    { key: 'use', header: 'Use', render: (space) => <span className="spaces-use">{usageText(space)}</span> },
    { key: 'rate', header: 'Current rate', render: currentRateText },
    {
      key: 'active',
      header: 'Active',
      render: (space) => (
        <Toggle
          checked={space.isActive}
          onChange={(isActive) => updateSpace(space.id, { isActive })}
          label={`${space.name} bookable`}
        />
      ),
    },
  ]

  return (
    <div className="spaces-page">
      <StaffPageHeader
        title={SPACES_INFO.title}
        subtitle={loadedText(
          loaded,
          'spaces',
          `${spaces.length} spaces · ${amenities.length} amenities · ${visiblePromotions.length} promotions`,
        )}
        actions={<Button onClick={() => setOpenPopup('space')}>Add space</Button>}
      />

      {problem && (
        <p className="page-problem" role="alert">
          {problem}
        </p>
      )}

      <div className="spaces-body">
        <div className="spaces-main">
          <Card className="staff-table-card spaces-table-card">
            <Table
              columns={spaceColumns}
              rows={spaces}
              getRowKey={(space) => space.id}
              onRowClick={openSpace}
              selectedKey={selectedSpace?.id}
              getRowClass={(space) => blackoutToday(space, spaces) && 'is-blacked-out'}
            />
          </Card>

          <div className="spaces-card-list">
            {spaces.map((space) => (
              <SpaceListCard
                key={space.id}
                space={space}
                blackout={blackoutToday(space, spaces)}
                onOpen={() => openSpace(space)}
                onToggleActive={(isActive) => updateSpace(space.id, { isActive })}
              />
            ))}
          </div>

          <AmenitiesCard amenities={amenities} onToggle={toggleAmenity} onAdd={() => setOpenPopup('amenity')} />

          <PromotionsCard
            promotions={visiblePromotions}
            onToggle={togglePromotion}
            onAdd={() => openPromotionForm(null)}
            onEdit={openPromotionForm}
          />

          <Button variant="secondary" className="spaces-mobile-add" onClick={() => setOpenPopup('space')}>
            + Add space
          </Button>
        </div>

        {/* Key resets the editor for a new space or a fresh API copy */}
        {selectedSpace && (
        <SpaceEditor
          key={`${selectedSpace.id}-${JSON.stringify(selectedSpace)}`}
          space={selectedSpace}
          roomCount={selectedRooms.length}
          blackouts={blackoutsFor(selectedSpace, spaces)}
          blackedOutToday={blackoutToday(selectedSpace, spaces)}
          rooms={selectedRooms}
          otherSpaces={spaces.filter((space) => space.id !== selectedSpace.id && space.parentId !== selectedSpace.id)}
          onAddBlackout={addBlackout}
          onRemoveBlackout={removeBlackout}
          onRateAction={rateAction}
          isOpen={isEditorOpen}
          onClose={() => setIsEditorOpen(false)}
          onSave={updateSpace}
          onUnsavedChange={setUnsavedChanges}
        />
        )}
      </div>

      {openPopup === 'space' && <AddSpaceForm onAdd={addSpace} onClose={() => setOpenPopup(null)} />}
      {openPopup === 'promotion' && (
        <PromotionForm
          promotion={editingPromotion}
          existingCodes={promotions.map((promo) => promo.code)}
          onSave={savePromotion}
          onClose={() => setOpenPopup(null)}
        />
      )}
      {openPopup === 'amenity' && (
        <AddAmenityForm onAdd={(amenity) => save(() => api.addAmenity(amenity))} onClose={() => setOpenPopup(null)} />
      )}
    </div>
  )
}
