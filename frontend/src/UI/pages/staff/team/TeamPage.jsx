import { useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import EmptyState from '../../../components/EmptyState'
import StatusChip from '../../../components/StatusChip'
import { useStaffUser } from '../../../hooks/staffUser'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { apiGet, apiPatch, apiPost } from '../../../../services/api'
import StaffPageHeader from '../components/StaffPageHeader'
import { initials } from '../account/accountRules'
import AddPersonForm from './AddPersonForm'
import ManagePersonDialog from './ManagePersonDialog'
import { TEAM_ROLES, canManage, canSeeTeam } from './teamRules'
import { ACCOUNT_STATUSES, STAFF_ROLES } from '../../../../lib/statuses'
import './TeamPage.css'

// Accounts are switched off, never deleted, so names stay on past work.
export default function TeamPage() {
  const { user } = useStaffUser()
  const [isAdding, setIsAdding] = useState(false)
  const [managingId, setManagingId] = useState(null)
  const allowed = canSeeTeam(user)
  const list = useApiData(() => (allowed ? apiGet('/staff/team') : Promise.resolve([])), [allowed])
  const accounts = list.data ?? []

  // Each returns the API error, or '' on success.
  async function addAccount(details) {
    try {
      await apiPost('/staff/team', details)
      list.reload()
      return ''
    } catch (error) {
      return error.message
    }
  }

  async function updateAccount(id, changes) {
    try {
      await apiPatch('/staff/team/' + id, changes)
      list.reload()
      return ''
    } catch (error) {
      return error.message
    }
  }

  async function resetPassword(id) {
    try {
      await apiPost('/staff/team/' + id + '/reset-password')
      return ''
    } catch (error) {
      return error.message
    }
  }

  if (!allowed) {
    return (
      <div className="team-page">
        <StaffPageHeader title="Team" subtitle="Who can use the staff console." />
        <Card>
          <EmptyState
            title="Only admins can manage the team."
            description="Ask an admin if your details need changing."
            actionLabel="Back to Today"
            actionTo="/staff"
          />
        </Card>
      </div>
    )
  }

  if (list.error) {
    return (
      <div className="team-page">
        <StaffPageHeader title="Team" subtitle="Who can use the staff console." />
        <Card>
          <EmptyState title={couldNotLoad('the team')} description={list.error.message} actionLabel="Try again" onAction={list.reload} />
        </Card>
      </div>
    )
  }

  // Super admin first, then admins, then staff; A–Z inside each
  const team = accounts
    .filter((account) => TEAM_ROLES.includes(account.role))
    .sort((a, b) => TEAM_ROLES.indexOf(a.role) - TEAM_ROLES.indexOf(b.role) || a.name.localeCompare(b.name))
  const active = team.filter((account) => account.isActive).length
  const managing = team.find((account) => account.id === managingId)

  return (
    <div className="team-page">
      <StaffPageHeader
        title="Team"
        subtitle={list.loading && !list.data ? 'Loading…' : `${team.length} people · ${active} active`}
        actions={<Button onClick={() => setIsAdding(true)}>Add person</Button>}
      />

      <Card as="ul" className="team-list">
        {team.map((person) => {
          const fullName = `${person.name} ${person.surname}`
          return (
            <li key={person.id} className={`team-person ${person.isActive ? '' : 'is-off'}`.trim()} aria-label={fullName}>
              <span className="team-avatar">{initials(person)}</span>
              <span className="team-who">
                <span className="team-name">{fullName}</span>
                <span className="team-email">{person.email}</span>
              </span>
              <span className="team-chips">
                <StatusChip statuses={STAFF_ROLES} status={person.role} />
                <StatusChip statuses={ACCOUNT_STATUSES} status={person.isActive ? 'active' : 'off'} />
              </span>
              <span className="team-action">
                {person.id === user.id && <span className="team-you">You</span>}
                {canManage(user, person) && (
                  <Button variant="secondary" size="sm" onClick={() => setManagingId(person.id)}>
                    Manage
                  </Button>
                )}
              </span>
            </li>
          )
        })}
      </Card>

      {/* The header's button is desktop only */}
      <Button variant="secondary" className="team-mobile-add" onClick={() => setIsAdding(true)}>
        + Add person
      </Button>

      {isAdding && <AddPersonForm accounts={accounts} me={user} onAdd={addAccount} onClose={() => setIsAdding(false)} />}
      {managing && (
        <ManagePersonDialog
          person={managing}
          me={user}
          onUpdate={(changes) => updateAccount(managing.id, changes)}
          onResetPassword={() => resetPassword(managing.id)}
          onClose={() => setManagingId(null)}
        />
      )}
    </div>
  )
}
