
import dayjs from 'dayjs'
import { create } from 'zustand'
import { getConfig } from '../utils';
import { FileData, Recurrence, User } from '../types/types';


export type Form = {
  id?: number,
  idnumber?: string,
  creator?: string,
  status: number, // 0: Unsaved, 1: Saved draft, 2: Live
  timecreated?: number,
  timemodified?: number,

  activityname: string;
  initialCampus: string;
  campus: string;
  initialActivitytype: string;
  activitytype: string;
  location: string;
  timestart: number;
  initialTimestart: number;
  timeend: number;
  initialTimeend: number;
  description: string;
  transport: string;
  cost: string;

  permissions: boolean;
  permissionsinitial: boolean,
  permissionslimit: string;
  permissionsdueby: number;
  permissionsent: boolean;
  canpermissionsend: boolean;
  riskassessment: string;
  existingriskassessment: FileData[];
  attachments: string;
  existingattachments: FileData[];

  studentlist: any[];
  studentlistjson: string;
  staffincharge: any[];
  staffinchargejson: string;
  planningstaff: any[];
  planningstaffjson: string;
  accompanyingstaff: any[];
  accompanyingstaffjson: string;
  secondincharge: any[];
  secondinchargejson: string;

  otherparticipants: string;
  categories: string[];
  categoriesjson: string;
  colourcategory: string;
  areasjson: string;
  displaypublic: boolean;
  pushpublic: boolean;

  assessmentid: string;

  stepname: string;

  stupermissions?: any[];
  isallday: boolean;

  recurring: boolean;
  recurrence: Recurrence;
  occurrences: {
    dates: any[],
    datesReadable: any[],
  };
  recurringAcceptChanges: boolean;
  hasUserAcknowledged: boolean;
  acknowledgers: User[];
};

type FormStore = Form & {
  setState: (newState: Form | null) => void,
  reset: () => void,
  [key: string]: any; // Allow string keys with any type as values
}

const defaults: Form = {
  id: 0,
  idnumber: '',
  creator: '',
  status: 0, // 0: Unsaved, 1: Saved draft, 2: Live
  timecreated: dayjs().unix(),
  timemodified: dayjs().unix(),
  activityname: '',
  initialCampus: '',
  campus: 'primary',
  initialActivitytype: '',
  activitytype: 'excursion',
  location: '',
  timestart: dayjs().unix(),
  initialTimestart: dayjs().unix(),
  timeend: dayjs().unix() + 3600, // Now plus 1 hour
  initialTimeend: dayjs().unix() + 3600, // Now plus 1 hour
  description: '',
  transport: '',
  cost: '',

  permissions: false,
  permissionsinitial: false,
  permissionslimit: '',
  permissionsdueby: dayjs().unix(),
  permissionsent: false,
  canpermissionsend: false,
  riskassessment: '',
  existingriskassessment: [],
  attachments: '',
  existingattachments: [],

  studentlist: [],
  studentlistjson: '',
  staffincharge: [getConfig().user],
  staffinchargejson: '',
  planningstaff: [],
  planningstaffjson: '',
  accompanyingstaff: [],
  accompanyingstaffjson: '',
  secondincharge: [],
  secondinchargejson: '',
  otherparticipants: '',
  categories: [],
  categoriesjson: '',
  colourcategory: '',
  areasjson: '',
  displaypublic: false,
  pushpublic: false,
  assessmentid: '',
  
  stepname: '',
  isallday: false,
  recurring: false,
  recurrence: {
    pattern: "Weekly",

    dailyPattern: "Every",
    dailyInterval: 1,

    weeklyInterval: 1,
    weeklyDays: ['Monday'],

    monthlyPattern: "Day",
    monthlyDay: 1,
    monthlyInterval: 1,
    monthlyNth: "First",
    monthlyNthDay: "Monday",

    yearlyInterval: 1,
    yearlyPattern: "On",
    yearlyNth: "First",
    yearlyNthDay: "Monday",
    yearlyNthMonth: "January",
    yearlyMonth: "January",
    yearlyMonthDay: 1,

    customDates: [],

    range: "End after",
    endBy: dayjs().unix().toString(),
    endAfter: 10,
  },
  occurrences: {
    dates: [],
    datesReadable: [],
  },
  recurringAcceptChanges: false,
  hasUserAcknowledged: false,
  acknowledgers: [],
};

const useFormStore = create<FormStore>((set) => ({
  ...defaults,
  setState: (newState) => set(newState || defaults),
  setRecurrence: (newRecurrence: Recurrence) => set({ recurrence: newRecurrence || defaults.recurrence }),
  reset: () => set(defaults),
  setHasUserAcknowledged: (hasUserAcknowledged: boolean) => set({ hasUserAcknowledged: hasUserAcknowledged }),
}))




export type Errors = {
  [key: string]: string[]
}

type FormValidationStore = {
  formErrors: Errors,
  rules: any,
  setFormErrors: (errors: Errors) => void,
  reset: () => void,
}


const useFormValidationStore = create<FormValidationStore>((set) => ({
  formErrors: {},
  rules: {
    activityname: [
      (value: string, formData: Form) => (value.length ? null : 'Activity name is required. '),
    ],
    timestart: [
      (value: string, formData: Form) => (Number(value) == formData.timeend || Number(value) > formData.timeend ? 'End time must be greater than start time. ' : null),
    ],
    /*categories: [
      (value: string[], formData: Form) => (value.length ? null : 'At least one category is required. '),
    ],*/
  },
  setFormErrors: (errors) => set({ formErrors: errors }),
  reset: () => set({formErrors: {}}),
}))

export { 
  defaults,
  useFormStore, 
  //useStaffDetailsStore,
  //useStudentListStore,
  useFormValidationStore
};